<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Printer;
use App\Services\CashierReceiptPrinterService;
use DateTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

/**
 * Prints one order's invoice to one cashier printer. Scoped to a single
 * printer (not "print to every cashier printer") so that if this
 * specific printer is unplugged/offline, only this job retries — mirrors
 * PrintOrderTicket's reasoning, including dedup via the jobs table
 * (see SkipsAlreadyQueuedPrints / CashierReceiptPrinterService::queuePrintJobs())
 * rather than a cache-based unique lock, which can orphan and silently
 * block reprints if the worker dies mid-job.
 */
class PrintCashierReceipt implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Order $order,
        public Printer $printer,
    ) {
    }

    public function retryUntil(): DateTime
    {
        return now()->addDay();
    }

    public function backoff(): int
    {
        return 30;
    }

    public function handle(CashierReceiptPrinterService $service): void
    {
        if (! $service->printToPrinter($this->order, $this->printer)) {
            throw new RuntimeException("Cashier receipt for order {$this->order->reference} at printer {$this->printer->name} could not be printed — will retry.");
        }
    }
}
