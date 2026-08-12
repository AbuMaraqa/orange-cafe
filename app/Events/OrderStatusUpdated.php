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

    public function __construct(public Order $order)
    {
    }

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
                'paid_at' => $this->order->paid_at?->toIso8601String(),
                'amount_paid_cents' => $this->order->amount_paid_cents,
                'total_cents' => $this->order->total_cents,
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
