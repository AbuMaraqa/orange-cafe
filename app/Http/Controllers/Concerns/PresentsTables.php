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
                'status' => $activeOrder->status,
                'status_color' => $activeOrder->statusColor(),
                'guest_count' => $activeOrder->guest_count,
                'total_cents' => $activeOrder->total_cents,
                'is_paid' => $activeOrder->isPaid(),
                'items_count' => $activeOrder->items->sum('quantity'),
                'items' => $activeOrder->items->map(fn ($item) => [
                    'id' => $item->id,
                    'product_name' => $item->product_name,
                    'quantity' => $item->quantity,
                ]),
                'created_at' => $activeOrder->created_at->toIso8601String(),
            ] : null,
        ];
    }
}
