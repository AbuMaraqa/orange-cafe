<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\PresentsOrders;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Accounting\BusinessDay;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    use PresentsOrders;

    public function index(Request $request): Response
    {
        // The café's own day, which need not start at midnight: a venue
        // serving until 3am would otherwise find its last three hours of
        // orders listed under tomorrow, on a screen whose whole purpose is
        // "what happened today". See BusinessDay.
        $businessDay = app(BusinessDay::class);

        $date = $request->filled('date')
            ? Carbon::parse($request->input('date'))
            : Carbon::parse($businessDay->today());

        $window = [$businessDay->startOf($date), $businessDay->endOf($date)];

        $status = $request->input('status');

        $orders = Order::query()
            ->with('table', 'items.modifiers')
            ->whereBetween('created_at', $window)
            ->when($status, fn ($query) => $query->where('status', $status))
            ->latest()
            ->get();

        $allForDay = $status ? Order::query()->whereBetween('created_at', $window)->get() : $orders;

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
