<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * Fired the moment a customer places an order, broadcast over Reverb so
 * every staff dashboard subscribed to the `orders` channel (waiter,
 * cashier, kitchen) sees it appear instantly — no polling.
 */
class OrderPlaced implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    /**
     * @param bool $isAddition The table already had an open ticket and these
     *                         items were added to it, rather than this being
     *                         the first order of the sitting. Staff screens
     *                         announce the two differently: "table 6 ordered"
     *                         is news, "table 6 ordered again" tells the
     *                         waiter something arrived for a table they have
     *                         already been to.
     */
    public function __construct(public Order $order, public bool $isAddition = false) {}


    public function broadcastOn(): array
    {
        return [new PrivateChannel('orders')];
    }

    public function broadcastAs(): string
    {
        return 'order.placed';
    }

    public function broadcastWith(): array
    {
        $this->order->loadMissing('table', 'items.modifiers');

        // This payload has to carry everything the floor plan renders,
        // because a table that appears over the socket is built from this
        // and nothing else — there is no page load behind it to fill in the
        // rest. It was missing `items_count`, `status_color` and `is_paid`,
        // which are exactly the three the table tile reads: the tile drew
        // an order with a blank item count and no paid/unpaid colour, so an
        // order arriving live looked like nothing had happened until
        // somebody refreshed the page and the server sent the full shape.
        //
        // Keep this in step with PresentsTables::presentTable()'s
        // `active_order` — the two describe the same thing to the same
        // component, one over HTTP and one over the socket.
        return [
            'is_addition' => $this->isAddition,
            'order' => [
                'id' => $this->order->id,
                'reference' => $this->order->reference,
                'table_id' => $this->order->table_id,
                'table_label' => $this->order->table?->label,
                'table_number' => $this->order->table_number,
                'guest_count' => $this->order->guest_count,
                'customer_name' => $this->order->customer_name,
                'status' => $this->order->status,
                'status_color' => $this->order->statusColor(),
                'total_cents' => $this->order->total_cents,
                'discount_cents' => $this->order->discount_cents ?? 0,
                'discount_reason' => $this->order->discount_reason,
                'is_paid' => $this->order->isPaid(),
                'amount_paid_cents' => $this->order->amount_paid_cents,
                'notes' => $this->order->notes,
                'items_count' => $this->order->items->sum('quantity'),
                'created_at' => $this->order->created_at->toIso8601String(),
                'items' => $this->order->items->map(fn ($item) => [
                    'id' => $item->id,
                    'product_name' => $item->product_name,
                    'quantity' => $item->quantity,
                    'unit_price_cents' => $item->unit_price_cents,
                    'subtotal_cents' => $item->subtotal_cents,
                    'notes' => $item->notes,
                    'modifiers' => $item->modifiers->pluck('modifier_name'),
                ]),
            ],
        ];
    }
}
