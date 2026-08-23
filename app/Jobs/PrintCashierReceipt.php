<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Services\CashierReceiptPrinterService;
use App\Services\Printing\PrinterHealth;
use App\Services\Printing\PrintQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * One order's bill, on one till printer.
 *
 * Same shape as PrintOrderTicket — bounded attempts, the same recovery
 * gate, the same refusal to reprint anything already on paper — with one
 * difference: a bill *does* have somewhere else to go. If the till printer
 * is dead, it prints in a kitchen that is already handling this order, so
 * the paper lands beside the food rather than nowhere. That is the
 * asymmetry the cafe asked for, and the reasoning is in
 * FallbackPrinterResolver.
 */
class PrintCashierReceipt implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = PrintJob::MAX_ATTEMPTS;

    public function __construct(
        public Order $order,
        public Printer $printer,
        public ?int $printJobId = null,
        /** Somebody pressed print, rather than a lifecycle hook firing. */
        public bool $requestedByHuman = false,
    ) {}

    public function backoff(): int
    {
        return 30;
    }

    public function handle(
        CashierReceiptPrinterService $service,
        PrintQueue $queue,
        PrinterHealth $health,
    ): void {
        $job = $this->printJobId ? PrintJob::find($this->printJobId) : null;

        if (! $job || $job->isPrinted()) {
            return;
        }

        // Automatic printing only — see the same gate in PrintOrderTicket.
        // A cashier asking for this one bill is the decision it is waiting
        // for, not the backlog it exists to suppress.
        $heldAfterRecovery = ! $this->requestedByHuman
            && ! $health->mayAutoPrint($this->printer, $this->order->created_at);

        if ($heldAfterRecovery) {
            $queue->markFailed($job, __('printing.held_after_recovery'), printerUnreachable: true);

            Log::info("Held bill for order {$this->order->reference}: {$this->printer->name} recovered after this order was placed.");

            return;
        }

        if (! $queue->claim($job)) {
            return;
        }

        if ($service->printToPrinter($this->order, $this->printer)) {
            $queue->markPrinted($job, $this->printer);

            return;
        }

        // The till is down. The bill goes to a kitchen already working on
        // this order — never to one with no part in it.
        if ($standIn = $service->printToFallback($this->order, $this->printer)) {
            $queue->markPrinted($job, $standIn);

            Log::warning("Bill for order {$this->order->reference} printed on {$standIn->name} because {$this->printer->name} could not be reached.");

            return;
        }

        $error = $service->lastError() ?: __('printing.unreachable');

        $queue->markFailed($job, $error, printerUnreachable: true);

        if ($job->attempts < PrintJob::MAX_ATTEMPTS) {
            $this->release($this->backoff());

            return;
        }

        Log::warning("Bill for order {$this->order->reference} at {$this->printer->name} gave up after {$job->attempts} attempts: {$error}");
    }
}
