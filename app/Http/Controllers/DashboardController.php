<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\PresentsOrders;
use App\Http\Controllers\Concerns\PresentsWaiterCalls;
use App\Models\Order;
use App\Services\Accounting\Cashbox;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    use PresentsOrders, PresentsWaiterCalls;

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

        // The money strip, for whoever is allowed to see it. Read through
        // the same Cashbox service the cash box screen and the nightly
        // closing use, so the dashboard cannot quietly disagree with them —
        // a summary that adds up differently from the page it summarises is
        // worse than no summary at all.
        $showMoney = $request->user()->can('cashbox.view');

        return Inertia::render('Dashboard', [
            'orders' => $orders->map(fn (Order $order) => $this->presentOrder($order)),
            'showOrders' => $canSeeOrders,
            'showMoney' => $showMoney,
            'money' => $showMoney ? $this->moneyToday() : null,
            // Only for staff who can see the floor at all. Someone who
            // cannot be shown an order has no business hearing which table
            // is calling — and the Reverb channel would refuse them anyway.
            'waiterCalls' => $canSeeOrders ? $this->pendingWaiterCalls() : collect(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function moneyToday(): array
    {
        $cashbox = app(Cashbox::class);
        $today = $cashbox->today();
        $day = $cashbox->day($today);
        $closing = $day['closing'];
        $isClosed = $closing && $closing->isClosed();

        return [
            'date' => $today,
            'balance_cents' => $day['balance_cents'],
            'collected_cents' => $day['total_in_cents'],
            'expenses_cents' => $day['total_out_cents'],
            'staff_deductions_cents' => $day['staff_deductions_cents'],
            'net_movement_cents' => $day['net_movement_cents'],
            'absences_count' => $day['absences_count'],
            'is_closed' => $day['is_closed'],
            // Only once the day has actually been counted. Before that there
            // is nothing to compare against, and a zero would read as
            // "balanced" when in fact nobody has looked yet.
            'difference_cents' => $isClosed ? $closing->difference_cents : null,
            'difference_state' => $isClosed ? $closing->differenceState() : null,
        ];
    }
}
