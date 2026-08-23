<?php

namespace Tests\Feature;

use App\Jobs\PrintCashierReceipt;
use App\Jobs\PrintOrderTicket;
use App\Models\Order;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\User;
use App\Services\CashierReceiptPrinterService;
use App\Services\KitchenPrinterService;
use App\Services\Printing\PrinterHealth;
use App\Services\Printing\PrintQueue;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Pressing print must print.
 *
 * Everything in the print queue is built to stop a *repeat* becoming a
 * second piece of paper — a double click, a lifecycle hook firing twice, a
 * worker that printed and died before recording it. None of those is a
 * person deciding they want another copy, and for a long time nothing here
 * could tell the two apart. The result was a button that, on exactly the
 * orders somebody needed it for, did nothing at all: no paper, no new row,
 * no error.
 *
 * Three separate guards each produced that outcome on their own, so each
 * gets its own test below:
 *
 *   1. the ticket already printed        → reserve() returned null
 *   2. the attempts were spent           → claim() refused the row
 *   3. the printer recovered afterwards  → the job held it
 *
 * The other half of the story is that none of it was ever reported. A
 * request that queued nothing answered with the same success message as one
 * that queued three tickets, which is why the last tests here are about
 * what the cashier is actually told.
 */
class ReprintOnDemandTest extends TestCase
{
    use RefreshDatabase;

    private function cashier(): User
    {
        $this->seed(RoleSeeder::class);

        // firstOrCreate: a test may press the button more than once, and
        // each press asks for the person pressing it.
        $user = User::firstOrCreate(
            ['email' => 'cashier@test.local'],
            [
                'name' => 'Cashier',
                'password' => 'password',
                'locale' => 'ar',
                'email_verified_at' => now(),
            ]
        );
        $user->assignRole('cashier');

        return $user;
    }

    private function printer(string $station = 'kitchen'): Printer
    {
        return Printer::create([
            'name' => 'طابعة '.$station,
            'station' => $station,
            'connection_type' => 'network',
            'ip_address' => '10.0.0.'.random_int(2, 250),
            'port' => 9100,
            'is_active' => true,
        ]);
    }

    private function order(string $station = 'kitchen'): Order
    {
        $order = Order::create(['status' => 'confirmed', 'locale' => 'ar', 'total_cents' => 2500]);

        $order->items()->create([
            'product_name' => 'شاورما',
            'station' => $station,
            'unit_price_cents' => 2500,
            'quantity' => 1,
            'subtotal_cents' => 2500,
        ]);

        return $order->fresh('items');
    }

    private function pressPrint(Order $order)
    {
        return $this->actingAs($this->cashier())->post(route('orders.print', $order));
    }

    // ---------------------------------------------------------------
    // 1. Already printed
    // ---------------------------------------------------------------

    public function test_pressing_print_on_a_ticket_that_already_printed_sends_a_fresh_copy(): void
    {
        Queue::fake();

        $this->printer();
        $order = $this->order();

        // The automatic print that happens when an order is confirmed.
        app(KitchenPrinterService::class)->queuePrintJobs($order);
        Queue::assertPushed(PrintOrderTicket::class, 1);

        PrintJob::query()->where('order_id', $order->id)->sole()
            ->update(['status' => PrintJob::PRINTED, 'printed_at' => now()]);

        $this->pressPrint($order);

        Queue::assertPushed(PrintOrderTicket::class, 2);
        $this->assertSame(2, PrintJob::query()->where('order_id', $order->id)->count());
    }

    /**
     * The copy is a new row, not a reset of the old one. The first ticket
     * genuinely printed and that stays on the record — otherwise the copy
     * count and the night's history quietly disagree.
     */
    public function test_the_copy_is_a_new_row_and_the_original_is_left_alone(): void
    {
        Queue::fake();

        $this->printer();
        $order = $this->order();

        app(KitchenPrinterService::class)->queuePrintJobs($order);
        $original = PrintJob::query()->where('order_id', $order->id)->sole();
        $original->update(['status' => PrintJob::PRINTED, 'printed_at' => now()]);

        $this->pressPrint($order);

        $this->assertTrue($original->fresh()->isPrinted(), 'The original still says it printed.');

        $copy = PrintJob::query()->where('order_id', $order->id)->latest('id')->first();
        $this->assertNotSame($original->id, $copy->id);
        $this->assertSame(PrintJob::PENDING, $copy->status);
        $this->assertStringEndsWith(':2', $copy->idempotency_key, 'It is recorded as copy 2.');
    }

    public function test_an_automatic_reprint_is_still_refused(): void
    {
        Queue::fake();

        $this->printer();
        $order = $this->order();

        app(KitchenPrinterService::class)->queuePrintJobs($order);
        PrintJob::query()->where('order_id', $order->id)->sole()
            ->update(['status' => PrintJob::PRINTED, 'printed_at' => now()]);

        // No human asked — a lifecycle hook firing twice must not produce a
        // second ticket. This is the guard the fix had to leave standing.
        app(KitchenPrinterService::class)->queuePrintJobs($order);

        Queue::assertPushed(PrintOrderTicket::class, 1);
        $this->assertSame(1, PrintJob::query()->where('order_id', $order->id)->count());
    }

    // ---------------------------------------------------------------
    // 2. Attempts spent
    // ---------------------------------------------------------------

    public function test_pressing_print_after_the_attempts_were_spent_sends_a_claimable_copy(): void
    {
        Queue::fake();

        $this->printer();
        $order = $this->order();

        app(KitchenPrinterService::class)->queuePrintJobs($order);

        // The printer was switched off and the job gave up.
        $settled = PrintJob::query()->where('order_id', $order->id)->sole();
        $settled->update(['status' => PrintJob::PRINTER_OFFLINE, 'attempts' => PrintJob::MAX_ATTEMPTS]);

        $this->pressPrint($order);

        $copy = PrintJob::query()->where('order_id', $order->id)->latest('id')->first();

        $this->assertNotSame($settled->id, $copy->id);
        // The point of the copy: the old row could not be claimed, so the
        // worker would have returned without printing anything.
        $this->assertFalse(app(PrintQueue::class)->claim($settled->fresh()));
        $this->assertTrue(app(PrintQueue::class)->claim($copy));
    }

    // ---------------------------------------------------------------
    // 3. The recovery gate
    // ---------------------------------------------------------------

    public function test_a_human_request_prints_even_after_the_printer_recovered(): void
    {
        $printer = $this->printer();
        $order = $this->order();

        // The printer died and came back after this order was taken, so
        // every automatic attempt on it is held.
        $printer->forceFill(['recovered_at' => now()->addMinute()])->save();

        $health = app(PrinterHealth::class);
        $this->assertFalse(
            $health->mayAutoPrint($printer->fresh(), $order->created_at),
            'Precondition: this order is on the wrong side of the recovery.'
        );

        $job = app(PrintQueue::class)->reserve(
            order: $order, printer: $printer, station: 'kitchen', kind: PrintJob::KIND_KITCHEN,
        );

        (new PrintOrderTicket($order, $printer->fresh(), 'kitchen', $job->id, requestedByHuman: true))
            ->handle(app(KitchenPrinterService::class), app(PrintQueue::class), $health);

        // It got as far as trying to print — the printer is unreachable in
        // a test, so it fails on the socket, not on the gate.
        $job->refresh();
        $this->assertSame(1, $job->attempts, 'The gate let it through to an actual attempt.');
        $this->assertNotSame(__('printing.held_after_recovery'), $job->last_error);
    }

    public function test_an_automatic_attempt_is_still_held_after_a_recovery(): void
    {
        $printer = $this->printer();
        $order = $this->order();
        $printer->forceFill(['recovered_at' => now()->addMinute()])->save();

        $job = app(PrintQueue::class)->reserve(
            order: $order, printer: $printer, station: 'kitchen', kind: PrintJob::KIND_KITCHEN,
        );

        (new PrintOrderTicket($order, $printer->fresh(), 'kitchen', $job->id))
            ->handle(app(KitchenPrinterService::class), app(PrintQueue::class), app(PrinterHealth::class));

        $job->refresh();
        $this->assertSame(0, $job->attempts, 'Never attempted — the backlog stays off the paper.');
        $this->assertSame(__('printing.held_after_recovery'), $job->last_error);
    }

    // ---------------------------------------------------------------
    // What the cashier is told
    // ---------------------------------------------------------------

    public function test_a_station_with_no_printer_is_reported_as_an_error(): void
    {
        Queue::fake();

        // No printer for 'kitchen' at all.
        $order = $this->order('kitchen');

        $this->pressPrint($order)->assertSessionHas('error');
        $this->pressPrint($order)->assertSessionMissing('success');
    }

    public function test_a_station_with_no_printer_still_leaves_a_record(): void
    {
        Queue::fake();

        $order = $this->order('grill');
        $this->pressPrint($order);

        $unroutable = PrintJob::query()->where('order_id', $order->id)->sole();

        $this->assertNull($unroutable->printer_id);
        $this->assertSame('grill', $unroutable->station);
        $this->assertSame(PrintJob::FAILED, $unroutable->status);
    }

    public function test_a_successful_request_is_reported_as_a_success(): void
    {
        Queue::fake();

        $this->printer();
        $order = $this->order();

        $this->pressPrint($order)->assertSessionHas('success');
    }

    /**
     * The till had the same hole as the kitchen, in reverse: a missing
     * cashier printer produced a log line and nothing else, so the bill
     * simply did not happen and no screen could be asked about it.
     */
    public function test_a_missing_cashier_printer_leaves_a_record_too(): void
    {
        Queue::fake();

        $order = $this->order();

        $result = app(CashierReceiptPrinterService::class)->queuePrintJobs($order);

        $this->assertFalse($result->queuedSomething());
        $this->assertTrue($result->hasUnroutableStations());

        $row = PrintJob::query()->where('kind', PrintJob::KIND_CASHIER)->sole();
        $this->assertSame(PrintJob::FAILED, $row->status);
        $this->assertNull($row->printer_id);
    }

    public function test_the_till_button_still_reprints_a_settled_bill(): void
    {
        Queue::fake();

        $this->printer('cashier');
        $order = $this->order();

        app(CashierReceiptPrinterService::class)->queuePrintJobs($order);
        PrintJob::query()->where('kind', PrintJob::KIND_CASHIER)->sole()
            ->update(['status' => PrintJob::PRINTED, 'printed_at' => now()]);

        $this->actingAs($this->cashier())->post(route('orders.print-cashier', $order));

        Queue::assertPushed(PrintCashierReceipt::class, 2);
    }
}
