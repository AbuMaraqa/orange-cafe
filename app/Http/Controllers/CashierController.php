<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\PresentsOrders;
use App\Http\Controllers\Concerns\PresentsTables;
use App\Http\Controllers\Concerns\PresentsWaiterCalls;
use App\Models\Order;
use App\Models\RestaurantTable;
use App\Models\ServiceCharge;
use Inertia\Inertia;
use Inertia\Response;

class CashierController extends Controller
{
    use PresentsOrders, PresentsTables, PresentsWaiterCalls;

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
            // The price list of things nobody cooks — a seat held past two
            // hours, corkage. Sent with the floor plan rather than fetched
            // when the sheet opens: it is three rows that change once a
            // season, and a request between "the table is asking" and
            // "charge them for it" is a request at the worst moment.
            'serviceCharges' => ServiceCharge::query()->active()->inOrder()->get()
                ->map(fn (ServiceCharge $charge) => [
                    'id' => $charge->id,
                    'name' => $charge->name,
                    'amount_cents' => $charge->amount_cents,
                ])->values(),
            // The till hears service requests too. On a quiet shift the
            // waiter board may not be open on any device at all, in which
            // case the request chimed to an empty room.
            'waiterCalls' => $this->pendingWaiterCalls(),
        ]);
    }
}
