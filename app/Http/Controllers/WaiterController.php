<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\PresentsOrders;
use App\Http\Controllers\Concerns\PresentsTables;
use App\Http\Controllers\Concerns\PresentsWaiterCalls;
use App\Models\Order;
use App\Models\RestaurantTable;
use Inertia\Inertia;
use Inertia\Response;

class WaiterController extends Controller
{
    use PresentsOrders, PresentsTables, PresentsWaiterCalls;

    public function __invoke(): Response
    {
        // Orders with no table (e.g. a takeaway order a waiter rang in
        // manually) aren't represented on the floor plan at all, so they're
        // still listed separately underneath it.
        $untabledOrders = Order::query()
            ->with('table', 'items.modifiers')
            ->whereNull('table_id')
            ->whereIn('status', config('orders.active_statuses'))
            ->latest()
            ->get();

        $tables = RestaurantTable::query()
            ->where('is_active', true)
            ->with(['orders' => fn ($query) => $query->whereIn('status', config('orders.active_statuses'))->latest()->with('items.modifiers')])
            ->get();

        return Inertia::render('Waiter/Index', [
            'tables' => $tables->map(fn (RestaurantTable $table) => $this->presentTable($table)),
            'untabledOrders' => $untabledOrders->map(fn (Order $order) => $this->presentOrder($order)),
            // Shared with the till and the dashboard — see PresentsWaiterCalls.
            'waiterCalls' => $this->pendingWaiterCalls(),
        ]);
    }
}
