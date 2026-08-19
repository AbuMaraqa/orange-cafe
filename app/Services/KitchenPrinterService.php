<?php

namespace App\Services;

use App\Jobs\PrintOrderTicket;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Printer;
use App\Services\Printing\FallbackPrinterResolver;
use App\Services\Printing\Concerns\SkipsAlreadyQueuedPrints;
use App\Services\Printing\EscPosImageRenderer;
use App\Services\Printing\PrinterTransport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Routes an order's items to the kitchen printer(s) responsible for their
 * station and sends an ESC/POS ticket — image-based (see
 * EscPosImageRenderer) so Arabic item names render correctly, delivered
 * over whichever transport that printer uses (see PrinterTransport).
 *
 * One PrintOrderTicket job is queued per (order, printer) pair rather than
 * one job for the whole order — if a grill printer is unreachable while
 * the kitchen and bar printers succeed, only the grill printer's job
 * retries. A single shared job would otherwise re-run printOrder() for
 * the entire order on every retry, re-sending duplicate tickets to the
 * stations that already succeeded, every 30s, for up to a day.
 */
class KitchenPrinterService
{
    use SkipsAlreadyQueuedPrints;

    public function __construct(
        private EscPosImageRenderer $renderer,
        private PrinterTransport $transport,
        private FallbackPrinterResolver $fallbacks,
    ) {
    }

    public function queuePrintJobs(Order $order): void
    {
        $order->loadMissing('items');

        $stations = $order->items
            ->map(fn (OrderItem $item) => $item->station ?? 'default')
            ->unique();

        foreach ($stations as $station) {
            $printers = Printer::query()->where('station', $station)->where('is_active', true)->get();

            if ($printers->isEmpty()) {
                Log::warning("No active printer configured for station '{$station}' — order {$order->reference} won't print there.");

                continue;
            }

            foreach ($printers as $printer) {
                if ($this->hasPendingJob(PrintOrderTicket::class, $order->id, $printer->id)) {
                    continue;
                }

                PrintOrderTicket::dispatch($order, $printer, $station);
            }
        }
    }

    public function printToStation(Order $order, Printer $printer, string $station, ?string $notice = null): bool
    {
        $order->loadMissing('items.modifiers', 'table');

        $items = $order->items->filter(fn (OrderItem $item) => ($item->station ?? 'default') === $station);

        if ($items->isEmpty()) {
            return true;
        }

        return $this->transport->send($printer, $this->buildTicket($order, $items, $notice));
    }

    /**
     * Prints this station's ticket on a stand-in printer after the real one
     * could not be reached. Returns the printer that took it, or null when
     * none of them would either.
     *
     * Deliberately stops at the first success: the ticket has been printed,
     * and a second copy from a later retry is a second order as far as the
     * kitchen is concerned.
     */
    public function printToFallback(Order $order, Printer $failed, string $station): ?Printer
    {
        foreach ($this->fallbacks->forKitchen($failed) as $standIn) {
            if ($this->printToStation($order, $standIn, $station, $this->fallbacks->notice($failed))) {
                return $standIn;
            }
        }

        return null;
    }

    protected function buildTicket(Order $order, Collection $items, ?string $notice = null): string
    {
        return $this->renderer->render('pdf.kitchen-ticket', [
            'order' => $order,
            'items' => $items,
            'labels' => __('pdf'),
            'notice' => $notice,
        ], 'rtl', max(60, 40 + $items->count() * 16));
    }
}
