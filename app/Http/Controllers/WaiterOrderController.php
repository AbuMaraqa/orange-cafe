<?php

namespace App\Http\Controllers;

use App\Events\OrderStatusUpdated;
use App\Http\Controllers\Concerns\BroadcastsSafely;
use App\Http\Requests\AddOrderItemsRequest;
use App\Models\Order;
use App\Models\RestaurantTable;
use App\Services\KitchenPrinterService;
use App\Services\MenuCatalogPresenter;
use App\Services\OrderItemWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lets a waiter place an order on a customer's behalf (e.g. a walk-in with
 * no phone, or a table that prefers not to use the QR menu) instead of
 * relying entirely on the customer self-service flow. Reuses the exact same
 * catalog (MenuCatalogPresenter) and checkout endpoint (OrderController)
 * as the public menu, so pricing/availability rules never diverge between
 * the two entry points. Also handles adding more items to a table's
 * already-running order, opened from the table popup on the floor plan.
 */
class WaiterOrderController extends Controller
{
    use BroadcastsSafely;

    public function create(Request $request, MenuCatalogPresenter $presenter): Response
    {
        $preselectedTableId = $request->integer('table_id') ?: null;
        $existingOrder = null;

        if ($preselectedTableId) {
            $activeOrder = RestaurantTable::find($preselectedTableId)?->activeOrder();

            if ($activeOrder) {
                $existingOrder = [
                    'id' => $activeOrder->id,
                    'reference' => $activeOrder->reference,
                    'items_count' => $activeOrder->items->sum('quantity'),
                    'total_cents' => $activeOrder->total_cents,
                ];
            }
        }

        return Inertia::render('Waiter/NewOrder', [
            'menus' => $presenter->visibleMenusPayload(),
            'tables' => RestaurantTable::query()
                ->where('is_active', true)
                ->orderBy('label')
                ->get()
                ->map(fn (RestaurantTable $table) => [
                    'id' => $table->id,
                    'label' => $table->label,
                    'seats' => $table->seats,
                    'is_occupied' => $table->activeOrder() !== null,
                ]),
            'preselectedTableId' => $preselectedTableId,
            'existingOrder' => $existingOrder,
        ]);
    }

    /**
     * Adds more items to a table's already-running order instead of
     * starting a separate one — e.g. the table orders dessert after their
     * mains arrived.
     */
    public function addItems(AddOrderItemsRequest $request, Order $order, OrderItemWriter $writer, KitchenPrinterService $printer): RedirectResponse
    {
        $data = $request->validated();

        // What this round actually added, per line — see addLines(). Needed
        // outside the transaction because the ticket is queued after it
        // commits, and never inside it: a print job dispatched from within
        // a transaction that then rolls back is paper for food nobody
        // ordered.
        $added = [];

        DB::transaction(function () use ($order, $data, $writer, &$added) {
            $addedCents = $writer->addLines($order, $data['items'], $added);
            $order->increment('total_cents', $addedCents);

            // The kitchen already considered this order done — adding more
            // items means there's more to prepare, so bring it back into
            // the active workflow rather than letting it silently sit.
            if (in_array($order->status, ['ready', 'completed'], true)) {
                $order->update(['status' => 'confirmed']);
            }
        });

        $this->safeBroadcast(new OrderStatusUpdated($order->fresh()), toOthers: true);
        // Just this round, on its own slip.
        //
        // It used to ask for the whole running ticket, and the result was
        // worse than a reprint: PrintJob's idempotency key is derived from
        // the order, printer, station and kind — nothing about the items —
        // so the key matched the ticket that had already printed, reserve()
        // saw a settled job and handed back null, and *nothing was queued
        // at all*. A waiter added a round to a confirmed table and the
        // kitchen was never told, silently.
        //
        // Scoping to the added lines gives the round its own key as well as
        // its own paper, so it both prints and cannot be mistaken for a
        // duplicate of the first ticket.
        $printer->queuePrintJobs($order, onlyItemIds: $added);

        // Their own screen, not the waiter's — a cashier adding a round to a
        // running table has no access to /waiter and would be answered with
        // a 403 for a request that succeeded. And back onto the table the
        // round was added to, which is the one they were looking at.
        return to_route(
            $request->user()->homeRouteName(),
            $order->table_id ? ['table' => $order->table_id] : [],
        )->with('success', __('menu.order_placed'));
    }
}
