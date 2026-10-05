<?php

namespace App\Services;

use App\Jobs\OpenCashDrawer;
use App\Jobs\PrintCashierReceipt;
use App\Models\Order;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Services\Printing\EscPosImageRenderer;
use App\Services\Printing\FallbackPrinterResolver;
use App\Services\Printing\PrintDispatchResult;
use App\Services\Printing\PrinterTransport;
use App\Services\Printing\PrintQueue;
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
    public const STATION = 'cashier';

    public function __construct(
        private EscPosImageRenderer $renderer,
        private PrinterTransport $transport,
        private PdfService $pdf,
        private RestaurantSettings $branding,
        private LanguageRegistry $languages,
        private FallbackPrinterResolver $fallbacks,
        private PrintQueue $queue,
    ) {}

    /**
     * Dispatches one job per cashier printer — mirrors
     * KitchenPrinterService::queuePrintJobs() so that if one till printer
     * is unreachable while another succeeds, only the failed one retries.
     */
    /**
     * @param  bool  $requestedByHuman  true when a cashier pressed the button
     */
    public function queuePrintJobs(Order $order, bool $requestedByHuman = false, ?int $userId = null): PrintDispatchResult
    {
        $printers = Printer::query()->where('station', self::STATION)->where('is_active', true)->get();

        if ($printers->isEmpty()) {
            // Recorded, not merely logged — the kitchen path has always done
            // this and the till path never did, which made the till the one
            // place a missing printer left no trace at all. The bill simply
            // did not happen, the cashier was told it had, and no screen in
            // the app could be asked about it afterwards.
            $this->queue->reserveUnroutable($order, self::STATION, PrintJob::KIND_CASHIER, $order->items()->count());

            Log::warning("No active cashier printer configured — order {$order->reference} invoice won't print there.");

            return new PrintDispatchResult(0, [self::STATION]);
        }

        $queued = 0;

        foreach ($printers as $printer) {
            // The old jobs-table scan that used to guard this line is gone,
            // and its removal fixes two things at once.
            //
            // It was slow: it pulled every row of the `jobs` table into PHP
            // and unserialized each payload, inside the HTTP request, every
            // time the cashier pressed print.
            //
            // And it silently swallowed prints. It returned true whenever
            // *any* job for this (order, printer) was still in the table —
            // including one stuck retrying from an earlier failure — and
            // then `continue`d before a PrintJob row was even created. The
            // button did nothing, no record appeared anywhere, and the
            // cashier had no way to tell that from a printer that was merely
            // slow. PrintQueue::reserve() below does the same job correctly:
            // one indexed lookup on a unique key, no deserialization, and a
            // row either way so nothing can vanish unrecorded.
            // A settled job cannot be sent again — claim() refuses it once
            // the attempts are spent, which is what stops a dead printer
            // being hammered. Pressing the button *is* the deliberate human
            // decision the reprint screen asks for, so it earns a fresh
            // copy; an automatic caller (payment, an order lifecycle hook)
            // does not, because nobody asked for a second piece of paper.
            // PrintQueue::reserve() is the one place that makes that call —
            // it used to be made here, and separately in the kitchen
            // service, and the kitchen's copy of it was simply missing.
            $job = $this->queue->reserve(
                order: $order,
                printer: $printer,
                station: self::STATION,
                kind: PrintJob::KIND_CASHIER,
                itemsCount: $order->items()->count(),
                requestedBy: $requestedByHuman ? $userId : null,
                requestedByHuman: $requestedByHuman,
            );

            if (! $job) {
                continue;
            }

            PrintCashierReceipt::dispatch($order, $printer, $job->id, $requestedByHuman);
            $queued++;
        }

        return new PrintDispatchResult($queued);
    }

    /** Why the last send failed, for the print job record and the alert. */
    public function lastError(): string
    {
        return $this->transport->lastError();
    }

    public function printToPrinter(Order $order, Printer $printer, ?string $notice = null): bool
    {
        // 'payments' for the payment-method lines on the receipt — see
        // Order::collectedByMethod() and pdf.invoice-thermal.
        $order->loadMissing('items.modifiers', 'table', 'payments');

        return $this->transport->send($printer, $this->buildReceipt($order, $notice), $order->created_at);
    }

    /**
     * Prints the bill on a kitchen already handling this order, after the
     * till printer could not be reached.
     *
     * Scoped to this order's own kitchens rather than "any other printer":
     * a bill appearing in a section that had no part in the order is paper
     * nobody expects and nobody can connect to a table.
     */
    public function printToFallback(Order $order, Printer $failed): ?Printer
    {
        foreach ($this->fallbacks->forCashier($failed, $order) as $standIn) {
            if ($this->printToPrinter($order, $standIn, $this->fallbacks->notice($failed))) {
                return $standIn;
            }
        }

        return null;
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

    protected function buildReceipt(Order $order, ?string $notice = null): string
    {
        $direction = $this->languages->direction(app()->getLocale());

        // mPDF embeds images by local filesystem path far more reliably
        // than by fetching the public URL over HTTP.
        $logoPath = $this->branding->logo_path && Storage::disk('public')->exists($this->branding->logo_path)
            ? Storage::disk('public')->path($this->branding->logo_path)
            : null;

        return $this->renderer->render('pdf.invoice-thermal', [
            'notice' => $notice,
            'order' => $order,
            'branding' => $this->branding,
            'logoPath' => $logoPath,
            'currencySymbol' => $this->branding->currency_symbol,
            'direction' => $direction,
            'labels' => __('pdf'),
        ], $direction);
    }
}
