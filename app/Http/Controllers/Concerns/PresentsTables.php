<?php

namespace App\Http\Controllers\Concerns;

use App\Models\RestaurantTable;

trait PresentsTables
{
    /**
     * @return array<string, mixed>
     */
    protected function presentTable(RestaurantTable $table): array
    {
        $activeOrder = $table->orders->first();

        return [
            'id' => $table->id,
            'label' => $table->label,
            'pos_x' => $table->pos_x,
            'pos_y' => $table->pos_y,
            'shape' => $table->shape,
            'seats' => $table->seats,
            'is_active' => $table->is_active,
            'is_occupied' => $activeOrder !== null,
            'active_order' => $activeOrder ? [
                'id' => $activeOrder->id,
                'reference' => $activeOrder->reference,
                'table_id' => $activeOrder->table_id,
                'customer_name' => $activeOrder->customer_name,
                'status' => $activeOrder->status,
                'status_color' => $activeOrder->statusColor(),
                'guest_count' => $activeOrder->guest_count,
                'total_cents' => $activeOrder->total_cents,
                'is_paid' => $activeOrder->isPaid(),
                'amount_paid_cents' => $activeOrder->amount_paid_cents,
                'notes' => $activeOrder->notes,
                'items_count' => $activeOrder->items->sum('quantity'),
                'items' => $activeOrder->items->map(fn ($item) => [
                    'id' => $item->id,
                    'product_name' => $item->product_name,
                    'quantity' => $item->quantity,
                    'unit_price_cents' => $item->unit_price_cents,
                    'subtotal_cents' => $item->subtotal_cents,
                    'notes' => $item->notes,
                    'modifiers' => $item->modifiers->pluck('modifier_name'),
                ]),
                'created_at' => $activeOrder->created_at->toIso8601String(),
            ] : null,
        ];
    }
}
