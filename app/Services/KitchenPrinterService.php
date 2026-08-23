<?php

namespace App\Services;

use App\Jobs\PrintOrderTicket;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Services\Printing\EscPosImageRenderer;
use App\Services\Printing\FallbackPrinterResolver;
use App\Services\Printing\PrintDispatchResult;
use App\Services\Printing\PrinterTransport;
use App\Services\Printing\PrintQueue;
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
    public function __construct(
        private EscPosImageRenderer $renderer,
        private PrinterTransport $transport,
        private FallbackPrinterResolver $fallbacks,
        private PrintQueue $queue,
    ) {}

    /** Why the last send failed, for the print job record and the alert. */
    public function lastError(): string
    {
        return $this->transport->lastError();
    }

    /**
     * @param  bool  $requestedByHuman  true when somebody pressed print,
     *                                  rather than an order lifecycle hook firing
     */
    /**
     * @param int[]|null $onlyItemIds queue tickets for just these lines.
     *                                Set when a round is added to an order
     *                                the kitchen has already been sent: the
     *                                cook gets a slip for the new dishes
     *                                rather than a reprint of the whole
     *                                table, which they would have to
     *                                compare against the one already on
     *                                their rail to find what changed.
     */
    public function queuePrintJobs(Order $order, bool $requestedByHuman = false, ?int $userId = null, ?array $onlyItemIds = null): PrintDispatchResult
    {
        $order->loadMissing('items');

        $lines = $onlyItemIds === null
            ? $order->items
            : $order->items->filter(fn (OrderItem $item) => array_key_exists($item->id, $onlyItemIds));

        // Lines nobody cooks never reach the pass. A service charge on the
        // same paper as food is a ticket a chef has to decide to ignore,
        // and a round that is *only* service charges would otherwise print
        // an empty ticket — or, worse, be counted as unroutable and
        // reported as food that never printed.
        $lines = $lines->filter(fn (OrderItem $item) => $item->prints_to_kitchen);

        // Deterministic, so a retry of the same dispatch resolves to the
        // same key and cannot print twice; distinct per round, so it never
        // collides with the ticket that already printed for this order.
        $scope = $onlyItemIds === null || $lines->isEmpty()
            ? null
            : 'add'.$lines->max('id');

        $byStation = $lines->groupBy(fn (OrderItem $item) => $item->station ?? 'default');

        $queued = 0;
        $unroutable = [];

        foreach ($byStation as $station => $items) {
            $printers = Printer::query()->forStation($station)->active()->get();

            if ($printers->isEmpty()) {
                // Recorded, not just logged. A station with no printer is
                // still food nobody has been told to cook, and the whole
                // point of the queue is that "never printed" is a question
                // the system can answer afterwards.
                $this->queue->reserveUnroutable($order, $station, PrintJob::KIND_KITCHEN, $items->count(), $scope);

                $unroutable[] = $station;

                Log::warning("No active printer configured for station '{$station}' — order {$order->reference} won't print there.");

                continue;
            }

            foreach ($printers as $printer) {
                $job = $this->queue->reserve(
                    order: $order,
                    printer: $printer,
                    station: $station,
                    kind: PrintJob::KIND_KITCHEN,
                    itemsCount: $items->count(),
                    requestedBy: $requestedByHuman ? $userId : null,
                    requestedByHuman: $requestedByHuman,
                    scope: $scope,
                );

                // null means this exact ticket is already on paper and
                // nobody asked for another. Not an error — the system
                // declining to print a second copy.
                if (! $job) {
                    continue;
                }

                PrintOrderTicket::dispatch($order, $printer, $station, $job->id, $requestedByHuman, $onlyItemIds);
                $queued++;
            }
        }

        return new PrintDispatchResult($queued, $unroutable);
    }

    /**
     * @param int[]|null $onlyItemIds print just these lines, or the whole
     *                                station's worth when null
     */
    public function printToStation(Order $order, Printer $printer, string $station, ?string $notice = null, ?array $onlyItemIds = null): bool
    {
        $order->loadMissing('items', 'table');

        // Both filters, again, because a ticket can be printed from here
        // directly — a retry, a fallback printer, a reprint asked for by
        // hand — without ever passing through queuePrintJobs.
        $items = $order->items
            ->filter(fn (OrderItem $item) => $item->prints_to_kitchen)
            ->filter(fn (OrderItem $item) => ($item->station ?? 'default') === $station);

        if ($onlyItemIds !== null) {
            $items = $items->filter(fn (OrderItem $item) => array_key_exists($item->id, $onlyItemIds));
        }

        if ($items->isEmpty()) {
            return true;
        }

        // Loaded after the filtering, not before it. Every PrintOrderTicket
        // re-fetches its own Order, so an order spanning three stations used
        // to pull the modifiers, product and category of every line three
        // times over to print a third of them each time — and an added round
        // pulled a twenty-line graph to print two. `items.product` is for the
        // prep time on each line and `items.menuItem.category` for the
        // section beside it; without them Blade lazy-loads one query per
        // line, mid-render.
        $items->loadMissing('modifiers', 'product', 'menuItem.category.translations');

        // The order's own timestamp travels with the send so a printer that
        // recovers on this very ticket does not hold the rest of the same
        // order — see PrinterHealth::markReachable.
        return $this->transport->send($printer, $this->buildTicket($order, $items, $notice, $onlyItemIds), $order->created_at);
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
    /**
     * Kept because the routing rules are the thing that decides, not the
     * caller — and for a kitchen ticket they now decide "nowhere". See
     * FallbackPrinterResolver::forKitchen().
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

    protected function buildTicket(Order $order, Collection $items, ?string $notice = null, ?array $addedQuantities = null): string
    {
        // No height passed: the renderer measures the ticket. An item count
        // cannot predict one — a note, a modifier line or a wrapped dish
        // name each add height the count knows nothing about, and guessing
        // short used to push the last items onto a second page that never
        // reached the printer.
        return $this->renderer->render('pdf.kitchen-ticket', [
            'order' => $order,
            'items' => $items,
            'labels' => __('pdf'),
            'notice' => $notice,
            // The cook has seen this table's ticket already. Saying so at
            // the top is the difference between "start these two dishes"
            // and "did I miss something the first time?".
            'isAddition' => $addedQuantities !== null,
            // What this round added, per line — not the line's new total.
            // Merging means a second order of something already on the
            // ticket bumps its quantity, and printing that total tells the
            // kitchen to cook the whole line again.
            'addedQuantities' => $addedQuantities ?? [],
        ], 'rtl');
    }
}
