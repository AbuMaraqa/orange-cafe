<?php

namespace App\Services;

use App\Jobs\OpenCashDrawer;
use App\Jobs\PrintCashierReceipt;
use App\Models\Order;
use App\Models\Printer;
use App\Services\Printing\Concerns\SkipsAlreadyQueuedPrints;
use App\Services\Printing\EscPosImageRenderer;
use App\Services\Printing\PrinterTransport;
use App\Settings\RestaurantSettings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mike42\Escpos\PrintConnectors\DummyPrintConnector;
use Mike42\Escpos\Printer as EscposPrinter;

/**
 * Prints the customer invoice (same content/layout as the PDF a staff
 * member can view via StaffOrderController::invoice()) to the cashier's
 * receipt printer, rather than requiring them to open a PDF and print it
 * manually. Station is fixed at "cashier" — unlike kitchen tickets, this
 * doesn't route by order item, it's always the one till printer (or
 * printers, if more than one is ever configured for that station).
 */
class CashierReceiptPrinterService
{
    use SkipsAlreadyQueuedPrints;

    private const STATION = 'cashier';

    public function __construct(
        private EscPosImageRenderer $renderer,
        private PrinterTransport $transport,
        private PdfService $pdf,
        private RestaurantSettings $branding,
        private LanguageRegistry $languages,
    ) {
    }

    /**
     * Dispatches one job per cashier printer — mirrors
     * KitchenPrinterService::queuePrintJobs() so that if one till printer
     * is unreachable while another succeeds, only the failed one retries.
     */
    public function queuePrintJobs(Order $order): void
    {
        $printers = Printer::query()->where('station', self::STATION)->where('is_active', true)->get();

        if ($printers->isEmpty()) {
            Log::warning("No active cashier printer configured — order {$order->reference} invoice won't print there.");

            return;
        }

        foreach ($printers as $printer) {
            if ($this->hasPendingJob(PrintCashierReceipt::class, $order->id, $printer->id)) {
                continue;
            }

            PrintCashierReceipt::dispatch($order, $printer);
        }
    }

    public function printToPrinter(Order $order, Printer $printer): bool
    {
        $order->loadMissing('items.modifiers', 'table');

        return $this->transport->send($printer, $this->buildReceipt($order));
    }

    /**
     * Dispatches one drawer-open job per cashier printer, mirroring
     * queuePrintJobs() — called from StaffOrderController::markPaid(), not
     * from printing, so the drawer opens on payment whether or not a
     * receipt happens to be printed for that order.
     */
    public function queueDrawerOpen(): void
    {
        Printer::query()->where('station', self::STATION)->where('is_active', true)
            ->each(fn (Printer $printer) => OpenCashDrawer::dispatch($printer));
    }

    public function openDrawer(Printer $printer): bool
    {
        $connector = new DummyPrintConnector;
        $escPrinter = new EscposPrinter($connector);
        $escPrinter->initialize();
        $escPrinter->pulse();
        $bytes = $connector->getData();
        $escPrinter->close();

        return $this->transport->send($printer, $bytes);
    }

    protected function buildReceipt(Order $order): string
    {
        $direction = $this->languages->direction(app()->getLocale());

        // mPDF embeds images by local filesystem path far more reliably
        // than by fetching the public URL over HTTP.
        $logoPath = $this->branding->logo_path && Storage::disk('public')->exists($this->branding->logo_path)
            ? Storage::disk('public')->path($this->branding->logo_path)
            : null;

        return $this->renderer->render('pdf.invoice-thermal', [
            'order' => $order,
            'branding' => $this->branding,
            'logoPath' => $logoPath,
            'currencySymbol' => $this->branding->currency_symbol,
            'direction' => $direction,
            'labels' => __('pdf'),
        ], $direction, max(90, 60 + $order->items->count() * 14));
    }
}
