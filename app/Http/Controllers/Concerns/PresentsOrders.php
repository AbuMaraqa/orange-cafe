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
            'discount_cents' => $order->discount_cents ?? 0,
            'discount_reason' => $order->discount_reason,
            'is_paid' => $order->isPaid(),
            'amount_paid_cents' => $order->amount_paid_cents,
            // A bill part-paid needs all three: what came in, what is still
            // owed, and that it is in that state at all. Without them the
            // till shows an unpaid bill for its full amount and takes the
            // whole thing again.
            'paid_so_far_cents' => $order->paidSoFarCents(),
            'outstanding_cents' => $order->outstandingCents(),
            'is_partially_paid' => $order->isPartiallyPaid(),
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
