<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\PresentsOrders;
use App\Models\Order;
use Inertia\Inertia;
use Inertia\Response;

class WaiterController extends Controller
{
    use PresentsOrders;

    public function __invoke(): Response
    {
        $orders = Order::query()
            ->with('table', 'items.modifiers')
            ->whereIn('status', config('orders.active_statuses'))
            ->latest()
            ->get();

        return Inertia::render('Waiter/Index', [
            'orders' => $orders->map(fn (Order $order) => $this->presentOrder($order)),
        ]);
    }
}
