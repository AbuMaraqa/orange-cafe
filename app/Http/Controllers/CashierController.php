<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\PresentsOrders;
use App\Http\Controllers\Concerns\PresentsTables;
use App\Models\Order;
use App\Models\RestaurantTable;
use Inertia\Inertia;
use Inertia\Response;

class CashierController extends Controller
{
    use PresentsOrders, PresentsTables;

    public function __invoke(): Response
    {
        // Orders with no table (takeaway, or a QR order placed without
        // picking one) don't show up on the floor plan, so — same as the
        // waiter's view — they're listed separately underneath it.
        $untabledOrders = Order::query()
            ->with('table', 'items.modifiers')
            ->whereNull('table_id')
            ->where(function ($query) {
                $query->whereIn('status', config('orders.active_statuses'))
                    ->orWhereNull('paid_at');
            })
            ->whereNotIn('status', ['cancelled'])
            ->latest()
            ->get();

        $tables = RestaurantTable::query()
            ->where('is_active', true)
            ->with(['orders' => fn ($query) => $query
                ->where(fn ($q) => $q->whereIn('status', config('orders.active_statuses'))->orWhereNull('paid_at'))
                ->whereNotIn('status', ['cancelled'])
                ->latest()
                ->with('items.modifiers')])
            ->get();

        return Inertia::render('Cashier/Index', [
            'tables' => $tables->map(fn (RestaurantTable $table) => $this->presentTable($table)),
            'untabledOrders' => $untabledOrders->map(fn (Order $order) => $this->presentOrder($order)),
        ]);
    }
}
