<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Services\KitchenPrinterService;
use App\Services\Printing\PrinterHealth;
use App\Services\Printing\PrintQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * One order's items, for one station, on one printer.
 *
 * Scoped this narrowly — not "print the order" — so a dead grill printer
 * retries alone while the kitchen and bar tickets that already succeeded
 * are never re-sent. That isolation is the whole fault-tolerance story: one
 * broken printer must not stop the till or the other kitchens.
 *
 * Two rules are enforced here and are easy to get wrong:
 *
 *  1. **Bounded retries.** Three attempts, then the job settles into a
 *     state a person can see. The old behaviour retried every 30 seconds
 *     for a day — 2,880 attempts at a printer that was simply switched off,
 *     and a ticket that finally emerges belongs to a table that left hours
 *     ago.
 *
 *  2. **No backlog on recovery.** Before sending, the job asks whether this
 *     order predates the printer's last recovery. If it does, it stops. A
 *     printer coming back at 13:00 must not produce the 12:00 orders it
 *     missed; those wait on the reprint screen for someone to decide they
 *     are still wanted.
 */
class PrintOrderTicket implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Laravel's own attempt limit, matched to the print job's.
     *
     * Both exist because they guard different things: this one stops the
     * queue worker re-running the job, PrintJob::MAX_ATTEMPTS decides when
     * the *record* stops being retryable. Keeping them equal means a job
     * cannot run out of one before the other and leave a row stuck at
     * "pending" that nothing will ever pick up.
     */
    public int $tries = PrintJob::MAX_ATTEMPTS;

    /**
     * Print only these order items, or the whole station's worth when null.
     *
     * Set when a waiter or cashier adds a round to a table that has already
     * been sent to the kitchen: the cook needs the two new dishes on their
     * own slip, not a reprint of a ticket they worked from twenty minutes
     * ago and would have to diff by eye.
     *
     * Declared rather than promoted on purpose. A promoted parameter's
     * default belongs to the constructor signature, and unserialize() never
     * calls a constructor — so a job queued by an older build would come
     * back with this property uninitialised and throw the moment handle()
     * read it. That is precisely how PrintCashierReceipt::$printJobId broke.
     * A declared default is restored on unserialize; a promoted one is not.
     *
     * @var int[]|null
     */
    public ?array $onlyItemIds = null;

    /**
     * Which print job row this run belongs to.
     *
     * Declared rather than promoted, like the two below it — see
     * $onlyItemIds for why that distinction is not cosmetic.
     */
    public ?int $printJobId = null;

    /**
     * Somebody pressed print, rather than an order lifecycle hook firing.
     * Carried all the way into handle() because the recovery gate there is
     * the last thing that can still silently refuse.
     */
    public bool $requestedByHuman = false;

    public function __construct(
        public Order $order,
        public Printer $printer,
        public string $station,
        ?int $printJobId = null,
        bool $requestedByHuman = false,
        ?array $onlyItemIds = null,
    ) {
        $this->printJobId = $printJobId;
        $this->requestedByHuman = $requestedByHuman;
        $this->onlyItemIds = $onlyItemIds;
    }

    /**
     * Long enough for a printer that is merely busy, short enough that all
     * three attempts are done inside two minutes — while the order is still
     * the current one.
     */
    public function backoff(): int
    {
        return 30;
    }

    public function handle(
        KitchenPrinterService $service,
        PrintQueue $queue,
        PrinterHealth $health,
    ): void {
        $job = $this->printJobId ? PrintJob::find($this->printJobId) : null;

        if (! $job || $job->isPrinted()) {
            // Already on paper, or the record is gone. Either way there is
            // nothing to print — and printing anyway is how a kitchen ends
            // up cooking the same order twice.
            return;
        }

        // The recovery gate. Checked here rather than at dispatch because a
        // job queued while the printer was alive can still be retrying
        // after it died and came back.
        //
        // It governs *automatic* printing only, which is what its name says
        // and what it was written for: a printer coming back must not spit
        // out the hour of orders it missed. A person pressing print on one
        // specific order is the deliberate decision this gate is holding the
        // ticket *for* — refusing them was the gate answering a question
        // nobody had asked, and there was no way to tell it from a printer
        // that was merely slow.
        $heldAfterRecovery = ! $this->requestedByHuman
            && ! $health->mayAutoPrint($this->printer, $this->order->created_at);

        if ($heldAfterRecovery) {
            $queue->markFailed(
                $job,
                __('printing.held_after_recovery'),
                printerUnreachable: true,
            );

            Log::info("Held ticket for order {$this->order->reference}: {$this->printer->name} recovered after this order was placed. Waiting for a manual reprint.");

            return;
        }

        if (! $queue->claim($job)) {
            // Another worker got there first.
            return;
        }

        if ($service->printToStation($this->order, $this->printer, $this->station, null, $this->onlyItemIds)) {
            $queue->markPrinted($job, $this->printer);

            return;
        }

        $error = $service->lastError() ?: __('printing.unreachable');

        $queue->markFailed($job, $error, printerUnreachable: true);

        // No stand-in, deliberately. A kitchen ticket has nowhere else to
        // go: putting the grill's steak on the drinks printer hands it to
        // someone who will not cook it and may assume it is theirs. The
        // cashier's alert carries the message instead — see
        // FallbackPrinterResolver.
        if ($job->attempts < PrintJob::MAX_ATTEMPTS) {
            // Throwing is what schedules the next attempt; a plain return
            // would mark the job done with no paper anywhere.
            $this->release($this->backoff());

            return;
        }

        Log::warning("Kitchen ticket for order {$this->order->reference} at {$this->printer->name} gave up after {$job->attempts} attempts: {$error}");
    }
}
