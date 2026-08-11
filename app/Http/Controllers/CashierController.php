<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\PresentsOrders;
use App\Models\Order;
use Inertia\Inertia;
use Inertia\Response;

class CashierController extends Controller
{
    use PresentsOrders;

    public function __invoke(): Response
    {
        $orders = Order::query()
            ->with('table', 'items.modifiers')
            ->where(function ($query) {
                $query->whereIn('status', config('orders.active_statuses'))
                    ->orWhereNull('paid_at');
            })
            ->whereNotIn('status', ['cancelled'])
            ->latest()
            ->get();

        return Inertia::render('Cashier/Index', [
            'orders' => $orders->map(fn (Order $order) => $this->presentOrder($order)),
        ]);
    }
}
