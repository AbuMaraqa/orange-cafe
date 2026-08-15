<?php

namespace App\Jobs;

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
 * Kicks one cashier printer's cash drawer open, dispatched when an order
 * is marked paid (not when its receipt prints — the two are independent,
 * see StaffOrderController::markPaid()). Queued and retryable, same as
 * PrintCashierReceipt, so a printer that's briefly unreachable still gets
 * the pulse once it's back rather than only getting one silent attempt —
 * but with a short retry window, since opening the drawer minutes after
 * the cashier has moved on is pointless.
 */
class OpenCashDrawer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Printer $printer)
    {
    }

    public function retryUntil(): DateTime
    {
        return now()->addMinutes(15);
    }

    public function backoff(): int
    {
        return 10;
    }

    public function handle(CashierReceiptPrinterService $service): void
    {
        if (! $service->openDrawer($this->printer)) {
            throw new RuntimeException("Cash drawer at printer {$this->printer->name} could not be opened — will retry.");
        }
    }
}
