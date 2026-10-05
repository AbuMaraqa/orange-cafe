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
        // The bill, which is not simply the newest ticket.
        //
        // A customer ordering again after their first round was confirmed
        // creates a pending order of its own, waiting for a member of staff
        // (see OrderController::joinableTableOrder). While it waits, the
        // table holds two live tickets and the newest is the one with almost
        // nothing on it — so taking `first()` showed a 15.00 tile for a
        // table sitting on a 102.00 bill, and the till would have charged
        // the 15.00.
        //
        // A ticket that has been through review is the bill. The pending one
        // is an inbox item, surfaced by `pending_orders` and its alert, and
        // it merges into the bill the moment somebody accepts it.
        $activeOrder = $table->orders->firstWhere(fn ($order) => $order->status !== 'pending')
            ?? $table->orders->first();

        // Rounds the table has asked for and nobody has accepted yet.
        //
        // Shown beside the bill rather than instead of it, because the
        // question a waiter is answering at the table is "what have we
        // ordered?" and the honest answer spans both: the food already on
        // its way, and the round that has just been asked for. Reading them
        // off two different screens is how a guest gets told they ordered
        // nothing when they ordered twice.
        //
        // The bill itself is excluded — when a table's only ticket is still
        // awaiting review it is the bill, and listing it in both places
        // would show every item twice.
        $pendingRounds = $table->orders
            ->filter(fn ($order) => $order->status === 'pending' && $order->id !== $activeOrder?->id)
            ->values();

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
                'discount_cents' => $activeOrder->discount_cents ?? 0,
                'discount_reason' => $activeOrder->discount_reason,
                'is_paid' => $activeOrder->isPaid(),
                'amount_paid_cents' => $activeOrder->amount_paid_cents,
                // See PresentsOrders: a part-paid bill has to say so, or the
                // till charges the full amount a second time.
                'paid_so_far_cents' => $activeOrder->paidSoFarCents(),
                'outstanding_cents' => $activeOrder->outstandingCents(),
                'is_partially_paid' => $activeOrder->isPartiallyPaid(),
                // See PresentsOrders: how it was settled, for the bill sheet.
                'payment_method' => $activeOrder->payment_method,
                'payment_reference' => $activeOrder->payment_reference,
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

            /**
             * Rounds asked for and not yet accepted, oldest first.
             *
             * Same item shape as the bill's, so the screen can render both
             * with one component and a guest reading over a waiter's
             * shoulder sees one list in one format.
             */
            'pending_rounds' => $pendingRounds
                ->sortBy('created_at')
                ->values()
                ->map(fn ($order) => [
                    'id' => $order->id,
                    'reference' => $order->reference,
                    'total_cents' => $order->total_cents,
                    'items_count' => $order->items->sum('quantity'),
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
                ]),

            /**
             * What the table has asked for in total — the bill plus every
             * round still waiting.
             *
             * The figure a guest means when they ask what they have ordered.
             * Computed here rather than added up in the template so the till,
             * the floor plan and the bill sheet cannot disagree about it.
             */
            'requested_total_cents' => ($activeOrder?->total_cents ?? 0)
                + $pendingRounds->sum('total_cents'),
        ];
    }
}
