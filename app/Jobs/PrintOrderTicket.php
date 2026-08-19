<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Printer;
use App\Services\KitchenPrinterService;
use DateTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

/**
 * Prints one order's items for one station to one printer. Scoped this
 * way (not "print the whole order") so that if this specific printer is
 * unplugged/offline, only this job retries — printers that already
 * succeeded for this order are never re-sent to. See
 * KitchenPrinterService::queuePrintJobs(), which dispatches one of these
 * per (order, printer) pair, skipping the dispatch entirely if a job for
 * that same pair is already sitting in the jobs table (see
 * SkipsAlreadyQueuedPrints) — that's what stops repeat "print" clicks or
 * repeat lifecycle triggers from stacking up parallel retry chains for
 * the same printer. That dedup used to be a cache-based lock
 * (ShouldBeUnique) instead, but the lock is only released when a job
 * finishes normally — if the worker process itself dies mid-job (it did,
 * once, from a MySQL disconnect), the lock outlives the job and silently
 * blocks every future print for that (order, printer) pair for a full
 * day with no error anywhere. Checking the jobs table directly has
 * nothing to orphan: if the row's gone, a new dispatch is allowed.
 */
class PrintOrderTicket implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Order $order,
        public Printer $printer,
        public string $station,
    ) {
    }

    /**
     * Keep retrying rather than giving up after a fixed number of
     * attempts — a stale ticket for an order placed a day ago is no
     * longer useful, but anything more recent than that should still go
     * out the moment the printer comes back online.
     */
    public function retryUntil(): DateTime
    {
        return now()->addDay();
    }

    /**
     * A fixed 30s gap between attempts: frequent enough that the ticket
     * prints soon after the printer reconnects, without hammering a
     * printer that's simply switched off with constant retries.
     */
    public function backoff(): int
    {
        return 30;
    }

    public function handle(KitchenPrinterService $service): void
    {
        if ($service->printToStation($this->order, $this->printer, $this->station)) {
            return;
        }

        // The station printer is unreachable. Rather than only queueing a
        // retry — which leaves the kitchen with no ticket for as long as the
        // printer stays dead — put the ticket out on the till printer, where
        // someone can pick it up and walk it over.
        if ($standIn = $service->printToFallback($this->order, $this->printer, $this->station)) {
            Log::warning("Kitchen ticket for order {$this->order->reference} printed on {$standIn->name} because {$this->printer->name} could not be reached.");

            // Done, deliberately: the ticket exists on paper. Retrying the
            // original printer once it comes back would put a second copy of
            // the same order in front of the kitchen.
            return;
        }

        // Nothing could print it anywhere. Throwing (instead of returning)
        // is what makes the queue retry per retryUntil()/backoff() above — a
        // plain return would mark the job "successful" even though no paper
        // ever came out.
        throw new RuntimeException("Kitchen ticket for order {$this->order->reference} at printer {$this->printer->name} could not be printed, and no stand-in printer accepted it — will retry.");
    }
}
