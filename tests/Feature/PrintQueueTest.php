<?php

namespace Tests\Feature;

use App\Jobs\PrintOrderTicket;
use App\Models\Order;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Services\CashierReceiptPrinterService;
use App\Services\KitchenPrinterService;
use App\Services\Printing\PrinterHealth;
use App\Services\Printing\PrinterTransport;
use App\Services\Printing\PrintQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * The printing rules, in the cafe's own order of priority:
 *
 *   1. never lose the order
 *   2. never print it twice
 *   3. every item to the right kitchen
 *   4. never to a kitchen it does not belong to
 *   5. notice when a printer dies
 *   6. retry, but not forever
 *   7. tell the cashier
 *   8. let a person reprint
 *
 * Each of those has at least one test below, because every one of them
 * fails silently in production: a ticket that never printed and a ticket
 * that printed twice both look like a working system right up until the
 * kitchen is wrong.
 */
class PrintQueueTest extends TestCase
{
    use RefreshDatabase;

    private function printer(string $station, string $name, string $status = PrinterHealth::ONLINE): Printer
    {
        $printer = Printer::create([
            'name' => $name,
            'station' => $station,
            'connection_type' => 'network',
            'ip_address' => '10.0.0.'.fake()->unique()->numberBetween(2, 250),
            'port' => 9100,
            'is_active' => true,
        ]);

        $printer->forceFill(['status' => $status])->save();

        return $printer;
    }

    /**
     * @param  array<string, string>  $itemsByStation  name => station
     */
    private function order(array $itemsByStation): Order
    {
        $order = Order::create([
            'reference' => 'R-'.fake()->unique()->numberBetween(1, 999999),
            'status' => 'pending',
            'total_cents' => 1000,
        ]);

        foreach ($itemsByStation as $name => $station) {
            // Through the relationship: order_id is not fillable, so a bare
            // create() would silently drop it and the item would belong to
            // nothing.
            $order->items()->create([
                'product_name' => $name,
                'station' => $station,
                'unit_price_cents' => 500,
                'quantity' => 1,
                'subtotal_cents' => 500,
            ]);
        }

        return $order->fresh('items');
    }

    /** A transport that reports whichever printers are "broken". */
    private function fakeTransport(array $brokenPrinterIds = []): void
    {
        $transport = Mockery::mock(PrinterTransport::class);

        $transport->shouldReceive('send')->andReturnUsing(
            fn (Printer $printer) => ! in_array($printer->id, $brokenPrinterIds, true)
        );
        $transport->shouldReceive('lastError')->andReturn('unreachable (test)');
        $transport->shouldReceive('probe')->andReturn(true);

        $this->app->instance(PrinterTransport::class, $transport);
    }

    // ---------------------------------------------------------------
    // 3 + 14. every item to the right kitchen, split per station
    // ---------------------------------------------------------------

    public function test_an_order_splits_into_one_job_per_station(): void
    {
        Queue::fake();

        $main = $this->printer('kitchen', 'المطبخ الرئيسي');
        $bar = $this->printer('drinks', 'المشروبات');

        $order = $this->order([
            'بيتزا' => 'kitchen',
            'برغر' => 'kitchen',
            'عصير' => 'drinks',
            'قهوة' => 'drinks',
        ]);

        app(KitchenPrinterService::class)->queuePrintJobs($order);

        $jobs = PrintJob::query()->get();

        $this->assertCount(2, $jobs, 'one job per station, not one per item and not one per printer');

        $kitchenJob = $jobs->firstWhere('printer_id', $main->id);
        $barJob = $jobs->firstWhere('printer_id', $bar->id);

        $this->assertSame('kitchen', $kitchenJob->station);
        $this->assertSame(2, $kitchenJob->items_count);
        $this->assertSame('drinks', $barJob->station);
        $this->assertSame(2, $barJob->items_count);
    }

    public function test_a_station_with_no_printer_is_still_recorded_as_unprinted(): void
    {
        Queue::fake();

        $this->printer('kitchen', 'المطبخ الرئيسي');

        // No printer exists for 'grill'.
        $order = $this->order(['برغر' => 'kitchen', 'ستيك' => 'grill']);

        app(KitchenPrinterService::class)->queuePrintJobs($order);

        $orphan = PrintJob::query()->where('station', 'grill')->first();

        $this->assertNotNull($orphan, 'an unprintable station must still leave a record');
        $this->assertNull($orphan->printer_id);
        $this->assertSame(PrintJob::FAILED, $orphan->status);
    }

    // ---------------------------------------------------------------
    // 4. never diverted to a kitchen it does not belong to
    // ---------------------------------------------------------------

    public function test_a_dead_grill_does_not_print_on_another_kitchen(): void
    {
        $main = $this->printer('kitchen', 'المطبخ الرئيسي');
        $bar = $this->printer('drinks', 'المشروبات');
        $grill = $this->printer('grill', 'الشواية');

        $this->fakeTransport(brokenPrinterIds: [$grill->id]);

        $order = $this->order(['برغر' => 'kitchen', 'عصير' => 'drinks', 'ستيك' => 'grill']);

        app(KitchenPrinterService::class)->queuePrintJobs($order);

        foreach (PrintJob::query()->get() as $job) {
            (new PrintOrderTicket($order, $job->printer, $job->station, $job->id))->handle(
                app(KitchenPrinterService::class),
                app(PrintQueue::class),
                app(PrinterHealth::class),
            );
        }

        $byStation = PrintJob::query()->get()->keyBy('station');

        $this->assertSame(PrintJob::PRINTED, $byStation['kitchen']->status);
        $this->assertSame(PrintJob::PRINTED, $byStation['drinks']->status);

        // The steak did not print, and — the part that matters — it did not
        // print anywhere else either.
        $this->assertNotSame(PrintJob::PRINTED, $byStation['grill']->status);
        $this->assertNull($byStation['grill']->printed_on_printer_id);
        $this->assertNotSame($main->id, $byStation['grill']->printed_on_printer_id);
        $this->assertNotSame($bar->id, $byStation['grill']->printed_on_printer_id);
    }

    // ---------------------------------------------------------------
    // 2. never twice
    // ---------------------------------------------------------------

    public function test_queueing_the_same_order_twice_creates_one_job(): void
    {
        Queue::fake();

        $this->printer('kitchen', 'المطبخ الرئيسي');
        $order = $this->order(['بيتزا' => 'kitchen']);

        $service = app(KitchenPrinterService::class);
        $service->queuePrintJobs($order);
        $service->queuePrintJobs($order);
        $service->queuePrintJobs($order);

        $this->assertSame(1, PrintJob::query()->count());
    }

    public function test_a_printed_job_is_never_sent_again(): void
    {
        $printer = $this->printer('kitchen', 'المطبخ الرئيسي');
        $this->fakeTransport();

        $order = $this->order(['بيتزا' => 'kitchen']);
        app(KitchenPrinterService::class)->queuePrintJobs($order);

        $job = PrintJob::query()->firstOrFail();

        $run = fn () => (new PrintOrderTicket($order, $printer, 'kitchen', $job->id))->handle(
            app(KitchenPrinterService::class),
            app(PrintQueue::class),
            app(PrinterHealth::class),
        );

        $run();
        $this->assertSame(PrintJob::PRINTED, $job->fresh()->status);

        $attemptsAfterFirst = $job->fresh()->attempts;

        // A retry arriving late, a duplicate dispatch, a worker that
        // restarted — none of them may produce a second ticket.
        $run();
        $run();

        $this->assertSame($attemptsAfterFirst, $job->fresh()->attempts, 'a printed job must not be attempted again');
        $this->assertSame(1, PrintJob::query()->count());
    }

    public function test_the_idempotency_key_is_unique_per_ticket(): void
    {
        $printer = $this->printer('kitchen', 'المطبخ الرئيسي');
        $order = $this->order(['بيتزا' => 'kitchen']);

        $queue = app(PrintQueue::class);

        $first = $queue->reserve($order, $printer, 'kitchen', PrintJob::KIND_KITCHEN);
        $second = $queue->reserve($order, $printer, 'kitchen', PrintJob::KIND_KITCHEN);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, PrintJob::query()->count());
    }

    // ---------------------------------------------------------------
    // 6. retry, but not forever
    // ---------------------------------------------------------------

    public function test_attempts_stop_after_three(): void
    {
        $printer = $this->printer('kitchen', 'المطبخ الرئيسي');
        $this->fakeTransport(brokenPrinterIds: [$printer->id]);

        $order = $this->order(['بيتزا' => 'kitchen']);
        app(KitchenPrinterService::class)->queuePrintJobs($order);

        $job = PrintJob::query()->firstOrFail();

        for ($i = 0; $i < 6; $i++) {
            (new PrintOrderTicket($order, $printer, 'kitchen', $job->id))->handle(
                app(KitchenPrinterService::class),
                app(PrintQueue::class),
                app(PrinterHealth::class),
            );
        }

        $job->refresh();

        $this->assertSame(PrintJob::MAX_ATTEMPTS, $job->attempts, 'the queue must stop trying, not hammer a dead printer');
        $this->assertSame(PrintJob::PRINTER_OFFLINE, $job->status);
        $this->assertNotNull($job->last_error);
    }

    // ---------------------------------------------------------------
    // 5. notice when a printer dies, and when it comes back
    // ---------------------------------------------------------------

    public function test_a_failed_send_marks_the_printer_down_with_a_reason(): void
    {
        $printer = $this->printer('kitchen', 'المطبخ الرئيسي');

        $health = app(PrinterHealth::class);

        // Twice: one refused connection is a printer that is busy, not one
        // that is off — and treating it as off is what silently held every
        // ticket in the cafe. See PrinterBlipDoesNotHoldTicketsTest.
        $health->markUnreachable($printer, 'Connection refused');
        $health->markUnreachable($printer->fresh(), 'Connection refused');

        $printer->refresh();

        $this->assertSame(PrinterHealth::OFFLINE, $printer->status);
        $this->assertSame('Connection refused', $printer->last_error);
        $this->assertNotNull($printer->offline_since);
    }

    public function test_a_printer_that_answers_but_refuses_work_is_reported_differently(): void
    {
        $printer = $this->printer('kitchen', 'المطبخ الرئيسي');
        $health = app(PrinterHealth::class);

        // A single hiccup is not a diagnosis — nor even a change of state.
        $health->markUnreachable($printer, 'no paper', reachedPrinter: true);
        $this->assertSame(PrinterHealth::ONLINE, $printer->fresh()->status);

        // A pattern is.
        $health->markUnreachable($printer->fresh(), 'no paper', reachedPrinter: true);
        $this->assertSame(PrinterHealth::UNRESPONSIVE, $printer->fresh()->status);
    }

    public function test_recovery_is_stamped(): void
    {
        $printer = $this->printer('kitchen', 'المطبخ الرئيسي', PrinterHealth::OFFLINE);
        // Down long enough to have actually missed work. A shorter outage
        // is a blip and deliberately stamps nothing — see
        // PrinterBlipDoesNotHoldTicketsTest.
        $printer->forceFill(['offline_since' => now()->subMinutes(20)])->save();
        $health = app(PrinterHealth::class);

        $this->assertTrue($health->markReachable($printer), 'coming back up is reported as a recovery');

        $printer->refresh();

        $this->assertSame(PrinterHealth::ONLINE, $printer->status);
        $this->assertNotNull($printer->recovered_at);
        $this->assertNull($printer->offline_since);

        // A printer that was already up has not "recovered".
        $this->assertFalse($health->markReachable($printer->fresh()));
    }

    // ---------------------------------------------------------------
    // The rule the cafe was most emphatic about:
    // recovery must NOT flush the backlog
    // ---------------------------------------------------------------

    public function test_an_order_from_before_the_recovery_is_not_printed_automatically(): void
    {
        $printer = $this->printer('kitchen', 'المطبخ الرئيسي');
        $this->fakeTransport();

        // Queue::fake so the sync driver does not run the job the instant it
        // is dispatched — the point under test is what happens when a
        // *retry* lands after the printer has come back.
        Queue::fake();

        // Taken at 12:00, while the printer was dead.
        $this->travelTo(now()->setTime(12, 0));
        $order = $this->order(['بيتزا' => 'kitchen']);
        app(KitchenPrinterService::class)->queuePrintJobs($order);
        $job = PrintJob::query()->firstOrFail();

        // The printer comes back at 13:00, after a full hour down.
        //
        // offline_since is what makes this a real outage rather than a
        // blip: an hour off genuinely missed orders, and that backlog is
        // exactly what must not print itself. A printer that flickered for
        // a few seconds stamps nothing — see
        // PrinterBlipDoesNotHoldTicketsTest.
        $printer->forceFill([
            'status' => PrinterHealth::OFFLINE,
            'offline_since' => now(),
        ])->save();

        $this->travelTo(now()->setTime(13, 0));
        app(PrinterHealth::class)->markReachable($printer->fresh());

        (new PrintOrderTicket($order, $printer->fresh(), 'kitchen', $job->id))->handle(
            app(KitchenPrinterService::class),
            app(PrintQueue::class),
            app(PrinterHealth::class),
        );

        $job->refresh();

        $this->assertNotSame(PrintJob::PRINTED, $job->status, 'an hour-old ticket must not appear in the kitchen unasked');
        $this->assertSame(0, $job->attempts, 'it must not even be attempted');

        $this->travelBack();
    }

    public function test_an_order_taken_after_the_recovery_prints_normally(): void
    {
        $printer = $this->printer('kitchen', 'المطبخ الرئيسي', PrinterHealth::OFFLINE);
        $this->fakeTransport();

        $this->travelTo(now()->setTime(13, 0));
        app(PrinterHealth::class)->markReachable($printer);

        // #103 arrives after the printer is back.
        $this->travelTo(now()->setTime(13, 5));
        $order = $this->order(['بيتزا' => 'kitchen']);
        app(KitchenPrinterService::class)->queuePrintJobs($order);
        $job = PrintJob::query()->firstOrFail();

        (new PrintOrderTicket($order, $printer->fresh(), 'kitchen', $job->id))->handle(
            app(KitchenPrinterService::class),
            app(PrintQueue::class),
            app(PrinterHealth::class),
        );

        $this->assertSame(PrintJob::PRINTED, $job->fresh()->status);

        $this->travelBack();
    }

    /**
     * The backlog stays visible and stays unprinted. Recovery is a fact
     * recorded, never an event that empties a queue.
     */
    public function test_recovery_leaves_the_backlog_alone(): void
    {
        $printer = $this->printer('kitchen', 'المطبخ الرئيسي');
        $this->fakeTransport(brokenPrinterIds: [$printer->id]);

        foreach (range(1, 3) as $ignored) {
            $order = $this->order(['بيتزا' => 'kitchen']);
            app(KitchenPrinterService::class)->queuePrintJobs($order);
        }

        $this->assertSame(3, PrintJob::query()->count());

        app(PrinterHealth::class)->markUnreachable($printer, 'down');
        app(PrinterHealth::class)->markReachable($printer->fresh());

        $this->assertSame(
            0,
            PrintJob::query()->where('status', PrintJob::PRINTED)->count(),
            'coming back online must not print anything by itself'
        );
        $this->assertSame(3, PrintJob::query()->unprinted()->count());
    }

    // ---------------------------------------------------------------
    // 8. a person can reprint, and that is the only way the backlog moves
    // ---------------------------------------------------------------

    public function test_a_reprint_creates_a_new_row_and_keeps_the_failure(): void
    {
        $printer = $this->printer('kitchen', 'المطبخ الرئيسي');
        $order = $this->order(['بيتزا' => 'kitchen']);

        $queue = app(PrintQueue::class);
        $original = $queue->reserve($order, $printer, 'kitchen', PrintJob::KIND_KITCHEN);
        $queue->markFailed($original, 'printer exploded');
        $original->forceFill(['attempts' => PrintJob::MAX_ATTEMPTS, 'status' => PrintJob::PRINTER_OFFLINE])->save();

        $copy = $queue->requeue($original->fresh());

        $this->assertNotSame($original->id, $copy->id);
        $this->assertSame(PrintJob::PENDING, $copy->status);

        // The original stays as evidence that the printer was down.
        $this->assertSame(PrintJob::PRINTER_OFFLINE, $original->fresh()->status);
        $this->assertSame('printer exploded', $original->fresh()->last_error);
    }

    // ---------------------------------------------------------------
    // The bug the cashier actually hit: press print, nothing happens
    // ---------------------------------------------------------------

    /**
     * A settled job must not block the button.
     *
     * Two things used to swallow this press. The jobs-table scan returned
     * true while any row for this (order, printer) lingered — including one
     * stuck retrying — and returned before a record was even created. And
     * once a job spends its attempts, claim() refuses it, so re-dispatching
     * the same row prints nothing either. Either way the cashier pressed
     * print, saw a success message, and no paper came out.
     */
    public function test_pressing_print_again_after_a_failure_produces_a_new_copy(): void
    {
        Queue::fake();

        $printer = $this->printer('cashier', 'الكاشير');
        $order = $this->order(['بيتزا' => 'kitchen']);

        $service = app(CashierReceiptPrinterService::class);

        $service->queuePrintJobs($order);

        $first = PrintJob::query()->firstOrFail();
        $first->forceFill([
            'status' => PrintJob::PRINTER_OFFLINE,
            'attempts' => PrintJob::MAX_ATTEMPTS,
        ])->save();

        // The cashier presses print again.
        $service->queuePrintJobs($order, requestedByHuman: true);

        $this->assertSame(2, PrintJob::query()->count(), 'the press must produce a fresh, sendable copy');

        $copy = PrintJob::query()->latest('id')->firstOrFail();
        $this->assertSame(PrintJob::PENDING, $copy->status);
        $this->assertSame(0, $copy->attempts);

        // And that copy really is claimable — the thing the old row was not.
        $this->assertTrue(app(PrintQueue::class)->claim($copy));
    }

    /**
     * An automatic caller gets no such favour: a lifecycle hook firing
     * twice must not put a second bill in the customer's hand.
     */
    public function test_an_automatic_requeue_does_not_duplicate_a_settled_job(): void
    {
        Queue::fake();

        $this->printer('cashier', 'الكاشير');
        $order = $this->order(['بيتزا' => 'kitchen']);

        $service = app(CashierReceiptPrinterService::class);
        $service->queuePrintJobs($order);

        PrintJob::query()->firstOrFail()->forceFill([
            'status' => PrintJob::PRINTER_OFFLINE,
            'attempts' => PrintJob::MAX_ATTEMPTS,
        ])->save();

        $service->queuePrintJobs($order);

        $this->assertSame(1, PrintJob::query()->count());
    }

    // ---------------------------------------------------------------
    // 1. the order survives regardless
    // ---------------------------------------------------------------

    public function test_the_order_is_saved_even_when_every_printer_is_dead(): void
    {
        $printer = $this->printer('kitchen', 'المطبخ الرئيسي');
        $this->fakeTransport(brokenPrinterIds: [$printer->id]);

        $order = $this->order(['بيتزا' => 'kitchen']);

        app(KitchenPrinterService::class)->queuePrintJobs($order);

        $job = PrintJob::query()->firstOrFail();
        (new PrintOrderTicket($order, $printer, 'kitchen', $job->id))->handle(
            app(KitchenPrinterService::class),
            app(PrintQueue::class),
            app(PrinterHealth::class),
        );

        // Printing failed; the order did not.
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
        $this->assertSame(1, $order->items()->count());
        $this->assertNotSame(PrintJob::PRINTED, $job->fresh()->status);
    }
}
