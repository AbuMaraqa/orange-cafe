<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The daily money picture: what was rung up (and by which waiter), what
 * was actually collected (and by which cashier), and what's still
 * outstanding — the accountability trail the restaurant owner asked for
 * on top of the existing per-status Daily Orders view.
 */
class AccountingController extends Controller
{
    public function index(Request $request): Response
    {
        $date = $request->filled('date')
            ? Carbon::parse($request->input('date'))
            : now();

        $orders = Order::query()
            ->with('createdBy', 'paidBy', 'table')
            ->whereDate('created_at', $date)
            ->where('status', '!=', 'cancelled')
            ->latest()
            ->get();

        $paidOrders = $orders->whereNotNull('paid_at');

        // What was actually taken at the till, not the nominal bill total —
        // a cashier rounding 202 down to 200 means the real cash drawer is
        // 2 short of the sum of order totals, and that gap should be
        // visible here rather than silently absorbed into "collected".
        $collectedCents = $paidOrders->sum(fn (Order $o) => $o->collectedCents());
        $roundingCents = $paidOrders->sum(fn (Order $o) => $o->total_cents - $o->collectedCents());

        return Inertia::render('Admin/Accounting/Index', [
            'date' => $date->toDateString(),
            'summary' => [
                'total_orders' => $orders->count(),
                'collected_cents' => $collectedCents,
                'rounding_cents' => $roundingCents,
                'outstanding_cents' => $orders->whereNull('paid_at')->sum('total_cents'),
            ],
            'byWaiter' => $orders->groupBy('created_by')->map(fn ($group) => [
                'name' => $group->first()->createdBy?->name,
                'orders_count' => $group->count(),
                'total_cents' => $group->sum('total_cents'),
            ])->values(),
            'byCashier' => $paidOrders->groupBy('paid_by')->map(fn ($group) => [
                'name' => $group->first()->paidBy?->name,
                'orders_count' => $group->count(),
                'total_cents' => $group->sum(fn (Order $o) => $o->collectedCents()),
            ])->values(),
            'orders' => $orders->map(fn (Order $order) => [
                'id' => $order->id,
                'reference' => $order->reference,
                'table_label' => $order->table?->label ?? $order->table_number,
                'created_by_name' => $order->createdBy?->name,
                'paid_by_name' => $order->paidBy?->name,
                'status' => $order->status,
                'status_color' => $order->statusColor(),
                'is_paid' => $order->isPaid(),
                'total_cents' => $order->total_cents,
                'amount_paid_cents' => $order->amount_paid_cents,
                'created_at' => $order->created_at->toIso8601String(),
            ]),
        ]);
    }
}
