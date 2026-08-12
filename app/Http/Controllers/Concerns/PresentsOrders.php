<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Order;

trait PresentsOrders
{
    protected function presentOrder(Order $order): array
    {
        return [
            'id' => $order->id,
            'reference' => $order->reference,
            'table_label' => $order->table?->label ?? $order->table_number,
            'guest_count' => $order->guest_count,
            'customer_name' => $order->customer_name,
            'status' => $order->status,
            'status_color' => $order->statusColor(),
            'total_cents' => $order->total_cents,
            'is_paid' => $order->isPaid(),
            'amount_paid_cents' => $order->amount_paid_cents,
            'notes' => $order->notes,
            'created_at' => $order->created_at->toIso8601String(),
            'items' => $order->items->map(fn ($item) => [
                'id' => $item->id,
                'product_name' => $item->product_name,
                'quantity' => $item->quantity,
                'unit_price_cents' => $item->unit_price_cents,
                'subtotal_cents' => $item->subtotal_cents,
                'notes' => $item->notes,
                'modifiers' => $item->modifiers->pluck('modifier_name'),
            ]),
        ];
    }
}
