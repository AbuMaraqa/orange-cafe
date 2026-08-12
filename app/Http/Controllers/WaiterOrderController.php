<?php

namespace App\Http\Controllers;

use App\Events\OrderStatusUpdated;
use App\Http\Controllers\Concerns\BroadcastsSafely;
use App\Http\Requests\AddOrderItemsRequest;
use App\Jobs\PrintOrderTicket;
use App\Models\Order;
use App\Models\RestaurantTable;
use App\Services\MenuCatalogPresenter;
use App\Services\OrderItemWriter;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
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
    public function addItems(AddOrderItemsRequest $request, Order $order, OrderItemWriter $writer): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($order, $data, $writer) {
            $addedCents = $writer->addLines($order, $data['items']);
            $order->increment('total_cents', $addedCents);

            // The kitchen already considered this order done — adding more
            // items means there's more to prepare, so bring it back into
            // the active workflow rather than letting it silently sit.
            if (in_array($order->status, ['ready', 'completed'], true)) {
                $order->update(['status' => 'confirmed']);
            }
        });

        $this->safeBroadcast(new OrderStatusUpdated($order->fresh()), toOthers: true);
        // Reprints the whole running ticket (not just what was just added) —
        // acceptable for now since kitchen staff already expect a fresh
        // ticket to represent the order's current full state.
        PrintOrderTicket::dispatch($order);

        return to_route('waiter.index')->with('success', __('menu.order_placed'));
    }
}
