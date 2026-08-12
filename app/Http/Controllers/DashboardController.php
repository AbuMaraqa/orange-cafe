<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\PresentsOrders;
use App\Models\Order;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    use PresentsOrders;

    public function __invoke(Request $request): Response
    {
        $canSeeOrders = $request->user()->hasAnyRole(['admin', 'waiter', 'cashier', 'kitchen']);

        $orders = $canSeeOrders
            ? Order::query()
                ->with('table', 'items.modifiers')
                ->whereIn('status', config('orders.active_statuses'))
                ->latest()
                ->get()
            : collect();

        return Inertia::render('Dashboard', [
            'orders' => $orders->map(fn (Order $order) => $this->presentOrder($order)),
            'showOrders' => $canSeeOrders,
        ]);
    }
}
