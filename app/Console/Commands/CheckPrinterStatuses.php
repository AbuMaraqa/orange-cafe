<?php

namespace App\Console\Commands;

use App\Models\Printer;
use App\Services\Printing\PrinterTransport;
use Illuminate\Console\Command;
use Throwable;

/**
 * Probes every active printer so the admin printer list shows real
 * online/offline state even when no orders are being printed — without
 * this, a printer's status would only update the next time something
 * happens to be sent to it (or someone clicks "Test connection"), so a
 * printer that went offline overnight would still show "online" from
 * yesterday's last successful print.
 *
 * Each printer is probed independently: one printer erroring never stops
 * the rest of the sweep, mirroring the same per-printer isolation
 * PrintOrderTicket relies on for actual printing.
 */
class CheckPrinterStatuses extends Command
{
    protected $signature = 'app:check-printer-statuses';

    protected $description = 'Probe every active printer and record whether it is currently reachable.';

    public function handle(PrinterTransport $transport): void
    {
        Printer::query()->where('is_active', true)->each(function (Printer $printer) use ($transport) {
            try {
                $transport->probe($printer);
            } catch (Throwable $e) {
                // Status was already recorded as offline by probe(); nothing more to do here.
            }
        });
    }
}
