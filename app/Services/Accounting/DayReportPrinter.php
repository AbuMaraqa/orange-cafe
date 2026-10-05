<?php

namespace App\Services\Accounting;

use App\Models\Printer;
use App\Models\User;
use App\Services\CashierReceiptPrinterService;
use App\Services\LanguageRegistry;
use App\Services\Printing\EscPosImageRenderer;
use App\Services\Printing\PrinterTransport;
use App\Settings\RestaurantSettings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The day's cash box, on the till printer.
 *
 * What a manager does at the end of a shift is count the drawer against a
 * figure, and until now that figure lived only on a screen — so the count
 * happened with a phone propped against the till, or on a number copied onto
 * the back of a receipt. This puts the same figures on the same roll of paper
 * the bills come off, which is also where they end up in the folder.
 *
 * ## Printed straight through, not queued
 *
 * Every other print in this app goes through PrintJob and the queue, and for
 * good reasons: a kitchen ticket nobody noticed failing is food that never
 * gets cooked, so it needs retries, a record, and an alert.
 *
 * This is the opposite situation. It is a deliberate act by somebody standing
 * at the printer, who is holding the drawer open and will see immediately
 * whether paper came out. A retry an hour later is not a rescue, it is a
 * second report with different numbers on it — the day moves. So this follows
 * TestPagePrinter instead: send it, and say plainly whether it went.
 *
 * The PrintJob machinery could not take it as it stands in any case; it is
 * keyed on an order (order_id, and an idempotency key built from order and
 * printer), and a day has no order.
 *
 * ## Rendered as an image
 *
 * Same reason as every other Arabic ticket: cheap thermal printers cannot
 * shape Arabic in their built-in fonts, so the page is composed by mPDF,
 * rasterised through pdftoppm and sent as ESC/POS bitmap. See
 * EscPosImageRenderer.
 */
class DayReportPrinter
{
    private string $lastError = '';

    public function __construct(
        private Cashbox $cashbox,
        private EscPosImageRenderer $renderer,
        private PrinterTransport $transport,
        private RestaurantSettings $branding,
        private LanguageRegistry $languages,
    ) {}

    /**
     * Prints one day to every active till printer.
     *
     * @return int how many printers took it
     */
    public function print(string $date, ?User $by = null): int
    {
        $printers = Printer::query()
            ->where('station', CashierReceiptPrinterService::STATION)
            ->where('is_active', true)
            ->get();

        if ($printers->isEmpty()) {
            // The same wording the print queue uses when a station has no
            // printer, rather than a second phrasing for one situation.
            $this->lastError = __('printing.no_printer_for_station', [
                'station' => CashierReceiptPrinterService::STATION,
            ]);

            return 0;
        }

        try {
            $bytes = $this->render($date, $by);
        } catch (Throwable $e) {
            // Rasterising is the step that fails on a machine without
            // pdftoppm, and the message it throws is the one worth showing.
            $this->lastError = $e->getMessage();

            Log::warning('Could not render the day report.', ['date' => $date, 'error' => $e->getMessage()]);

            return 0;
        }

        $sent = 0;

        foreach ($printers as $printer) {
            if ($this->transport->send($printer, $bytes)) {
                $sent++;

                continue;
            }

            // Kept rather than overwritten blindly: with two till printers,
            // one working and one unplugged, the message that matters is the
            // failure — but only if nothing else succeeded.
            $this->lastError = $this->transport->lastError() ?: __('printing.unreachable');
        }

        if ($sent > 0) {
            $this->lastError = '';

            Log::info('Day report printed.', ['date' => $date, 'printers' => $sent, 'by' => $by?->id]);
        }

        return $sent;
    }

    public function lastError(): string
    {
        return $this->lastError;
    }

    /**
     * The ESC/POS bytes for one day. Public so a test can assert what is on
     * the paper without a printer attached.
     */
    public function render(string $date, ?User $by = null): string
    {
        $day = $this->cashbox->day($date);

        // Asked for separately rather than folded into day(): that array is the
        // drawer equation, and every screen and every close recomputes it.
        // What was sold is a different question, wanted on paper and nowhere
        // else, and it costs a scan of the night's bill lines to answer.
        $items = $this->cashbox->itemsSold($date);

        // Same reasoning: the bill-by-bill list is a long scan of the night's
        // payments that only the paper asks for.
        $orders = $this->cashbox->ordersCollected($date);

        $direction = $this->languages->direction(app()->getLocale());

        // By filesystem path: mPDF embeds a local file far more reliably than
        // it fetches a URL, and on the packaged app there is no guarantee the
        // HTTP route is even reachable from the process doing the rendering.
        $logoPath = $this->branding->logo_path && Storage::disk('public')->exists($this->branding->logo_path)
            ? Storage::disk('public')->path($this->branding->logo_path)
            : null;

        return $this->renderer->render('pdf.day-report-thermal', [
            'day' => $day,
            'items' => $items,
            'orders' => $orders,
            'closing' => $day['closing'],
            'branding' => $this->branding,
            'logoPath' => $logoPath,
            'currencySymbol' => $this->branding->currency_symbol,
            'printedAt' => now()->format('Y-m-d H:i'),
            'printedBy' => $by?->name,
            'labels' => __('pdf'),
        ], $direction);
    }
}
