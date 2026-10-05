<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderPayment;
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
            ->with('createdBy', 'paidBy', 'table', 'payments')
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

        // How that money arrived. Read off the payment timeline rather than
        // off each bill's own column, because a table that put half on a card
        // and half in cash made two handovers and the column remembers only
        // the last one — splitting on it would file the whole bill under
        // whichever way it happened to finish.
        //
        // Card is the half that is known exactly; cash is what is left. That
        // is the honest direction of the subtraction: a card payment can only
        // exist by having been recorded as one, while everything predating the
        // card reader — and anything the importer or a seeder wrote — is cash.
        // Deriving cash instead of summing it also means the two halves cannot
        // fail to add up to the figure printed above them, which on a screen
        // built to trace money is the one thing that must not happen.
        $cardCollectedCents = $paidOrders->sum(
            fn (Order $o) => $o->collectedByMethod()[OrderPayment::METHOD_CARD] ?? 0
        );
        $cashCollectedCents = $collectedCents - $cardCollectedCents;

        return Inertia::render('Admin/Accounting/Index', [
            'date' => $date->toDateString(),
            'summary' => [
                'total_orders' => $orders->count(),
                'collected_cents' => $collectedCents,
                'cash_collected_cents' => $cashCollectedCents,
                'card_collected_cents' => $cardCollectedCents,
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
                // This screen is the accountability trail — who rang it up,
                // who took the money — and after a card reader arrives "how"
                // is part of that. Without it a row showing money collected
                // gives no way to tell a note in the drawer from a charge
                // that will land in the bank on Thursday.
                'payment_method' => $order->payment_method,
                'payment_reference' => $order->payment_reference,
                'created_at' => $order->created_at->toIso8601String(),
            ]),
        ]);
    }
}
