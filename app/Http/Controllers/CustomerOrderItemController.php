<?php

namespace App\Http\Controllers;

use App\Events\OrderStatusUpdated;
use App\Http\Controllers\Concerns\BroadcastsSafely;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\GuestOrderLines;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * A guest changing their own order from their phone, before a waiter has
 * confirmed it.
 *
 * Until confirmation nothing of theirs has reached the kitchen — a
 * customer's round prints at StaffOrderController::confirm(), not when it is
 * placed — so a change here costs nobody anything. The waiter's screen
 * updates, and the ticket they accept is the one the guest actually wants.
 * Calling a waiter over to take a coffee off an order nobody has looked at
 * yet is a trip for nothing.
 *
 * After confirmation the door closes. The kitchen has the paper, and any
 * change from then on is the waiter's, the same as before this existed.
 *
 * Only the guest's own share of a line, never anybody else's at the table —
 * see GuestOrderLines.
 */
class CustomerOrderItemController extends Controller
{
    use BroadcastsSafely;

    public function __construct(protected GuestOrderLines $guestLines) {}

    /**
     * Sets how many of this line the guest wants. Their own share only: on a
     * line two guests ordered into, the other guest's plates stay put.
     */
    public function update(Request $request, Order $order, OrderItem $item): RedirectResponse
    {
        // The same ceiling placing the order has — StoreOrderRequest.
        $quantity = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:50'],
        ])['quantity'];

        return $this->changeOwnLine($order, $item, function (OrderItem $item, int $mine) use ($quantity) {
            $item->quantity += $quantity - $mine;
            $item->subtotal_cents = $item->unit_price_cents * $item->quantity;
            $item->save();

            return $quantity;
        });
    }

    /**
     * Takes the guest's share of this line off the order. The line goes with
     * it only when nobody else ordered into it.
     */
    public function destroy(Order $order, OrderItem $item): RedirectResponse
    {
        return $this->changeOwnLine($order, $item, function (OrderItem $item, int $mine) {
            $item->quantity -= $mine;

            if ($item->quantity <= 0) {
                $item->delete();
            } else {
                $item->subtotal_cents = $item->unit_price_cents * $item->quantity;
                $item->save();
            }

            return 0;
        });
    }

    /**
     * The gate both routes share, and the arithmetic after it.
     *
     * The order row is locked while the line changes, because the waiter may
     * be pressing "confirm" at the same moment. Whichever gets there first
     * wins outright: an edit that lands first is in the ticket the kitchen
     * prints, and one that lands second is refused and changes nothing —
     * never a ticket printed one way and billed another.
     *
     * Being too late is an ordinary outcome, not an attack, so it answers
     * with a message rather than a 403. Touching somebody else's line is not,
     * and does get the 403.
     *
     * @param  Closure(OrderItem, int): int  $change  given the line and the
     *                                                guest's share of it,
     *                                                returns their new share
     */
    private function changeOwnLine(Order $order, OrderItem $item, Closure $change): RedirectResponse
    {
        abort_if($item->order_id !== $order->id, 404);

        $result = DB::transaction(function () use ($order, $item, $change) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'pending' || $locked->isPaid()) {
                return 'confirmed';
            }

            // Re-read under the lock: a member of staff may have changed or
            // removed this line since the page was loaded.
            $item = OrderItem::whereKey($item->id)->where('order_id', $locked->id)->firstOrFail();
            $mine = $this->guestLines->mine($item);

            abort_if($mine === 0, 403);

            $this->guestLines->set($item, $change($item, $mine));
            $locked->refresh()->recalculateTotal();

            // Everything this guest asked for is gone and nobody else at the
            // table has anything on it. An empty ticket left pending would
            // sit on the waiter's screen as one more thing to confirm, with
            // nothing on it to cook.
            if (! $locked->items()->exists()) {
                $locked->update(['status' => 'cancelled']);

                Log::info("Order {$locked->reference} withdrawn by the customer before confirmation.");

                return 'withdrawn';
            }

            return 'changed';
        });

        if ($result === 'confirmed') {
            return back()->withErrors(['order' => __('menu.order_already_confirmed')]);
        }

        $order->refresh();

        // The waiter's screen shows a pending order's lines, and has to show
        // the ones they are about to confirm, not the ones the guest started
        // with.
        $this->safeBroadcast(new OrderStatusUpdated($order));

        if ($result === 'withdrawn') {
            // Back to the menu of the table they are sitting at, with nothing
            // ordered — an order page for a cancelled order is a dead end.
            return to_route('menu.index', array_filter([
                'table_id' => $order->table_id,
                'table_label' => $order->table?->label ?? $order->table_number,
            ]));
        }

        return back();
    }
}
