<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

class OrderStatusUpdated implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(public Order $order) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('orders')];
    }

    public function broadcastAs(): string
    {
        return 'order.status-updated';
    }

    public function broadcastWith(): array
    {
        $this->order->loadMissing('items.modifiers');

        return [
            'order' => [
                'id' => $this->order->id,
                'reference' => $this->order->reference,
                'table_id' => $this->order->table_id,
                'status' => $this->order->status,
                // Same two the table tile reads and this payload did not
                // send: a round added to a running table changed its total
                // on every screen while the item count beside it stayed on
                // the number it had at page load.
                'status_color' => $this->order->statusColor(),
                'items_count' => $this->order->items->sum('quantity'),
                'paid_at' => $this->order->paid_at?->toIso8601String(),
                'amount_paid_cents' => $this->order->amount_paid_cents,
                // A part-payment on one screen has to reach the others, or
                // two cashiers take the same money twice.
                'paid_so_far_cents' => $this->order->paidSoFarCents(),
                'outstanding_cents' => $this->order->outstandingCents(),
                'is_partially_paid' => $this->order->isPartiallyPaid(),
                'total_cents' => $this->order->total_cents,
                'discount_cents' => $this->order->discount_cents ?? 0,
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
