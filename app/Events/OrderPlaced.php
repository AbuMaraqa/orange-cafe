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

    public function __construct(public Order $order)
    {
    }

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

        return [
            'order' => [
                'id' => $this->order->id,
                'reference' => $this->order->reference,
                'table_label' => $this->order->table?->label,
                'table_number' => $this->order->table_number,
                'guest_count' => $this->order->guest_count,
                'customer_name' => $this->order->customer_name,
                'status' => $this->order->status,
                'total_cents' => $this->order->total_cents,
                'notes' => $this->order->notes,
                'created_at' => $this->order->created_at->toIso8601String(),
                'items' => $this->order->items->map(fn ($item) => [
                    'product_name' => $item->product_name,
                    'quantity' => $item->quantity,
                    'modifiers' => $item->modifiers->pluck('modifier_name'),
                ]),
            ],
        ];
    }
}
