<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\PresentsOrders;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    use PresentsOrders;

    public function index(Request $request): Response
    {
        $date = $request->filled('date')
            ? Carbon::parse($request->input('date'))
            : now();

        $status = $request->input('status');

        $orders = Order::query()
            ->with('table', 'items.modifiers')
            ->whereDate('created_at', $date)
            ->when($status, fn ($query) => $query->where('status', $status))
            ->latest()
            ->get();

        $allForDay = $status ? Order::query()->whereDate('created_at', $date)->get() : $orders;

        return Inertia::render('Admin/Orders/Index', [
            'orders' => $orders->map(fn (Order $order) => $this->presentOrder($order)),
            'date' => $date->toDateString(),
            'status' => $status,
            'statusCounts' => collect(config('orders.statuses'))->keys()
                ->mapWithKeys(fn (string $key) => [$key => $allForDay->where('status', $key)->count()]),
            'summary' => [
                'total_orders' => $allForDay->count(),
                'paid_revenue_cents' => $allForDay->whereNotNull('paid_at')->sum('total_cents'),
            ],
        ]);
    }
}
