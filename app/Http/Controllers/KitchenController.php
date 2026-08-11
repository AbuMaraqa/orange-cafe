<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\PresentsOrders;
use App\Models\Order;
use Inertia\Inertia;
use Inertia\Response;

class KitchenController extends Controller
{
    use PresentsOrders;

    public function __invoke(): Response
    {
        $orders = Order::query()
            ->with('table', 'items.modifiers')
            ->whereIn('status', ['pending', 'confirmed', 'preparing'])
            ->latest()
            ->get();

        return Inertia::render('Kitchen/Index', [
            'orders' => $orders->map(fn (Order $order) => $this->presentOrder($order)),
        ]);
    }
}
