<?php

namespace App\Services\Accounting;

use App\Models\Absence;
use App\Models\CashMovement;
use App\Models\DailyClosing;
use App\Models\Expense;
use App\Models\Order;
use Illuminate\Support\Carbon;

/**
 * Every number the cash box shows, computed in exactly one place.
 *
 * The rule the whole thing rests on: **the drawer moves only when physical
 * cash moves.** It is the reason the figures can be reconciled against a
 * count at all, and it is what decides which of the cafe's "خصومات" belong
 * in the balance:
 *
 *  - a refund handed back, an expense paid in cash, an advance given to a
 *    waiter — cash left the drawer, so the drawer is lighter;
 *  - an absence deduction, a discount given on a bill — no cash moved. The
 *    first reduces what the cafe owes an employee at month end; the second
 *    was never collected in the first place, so it is already absent from
 *    what was taken.
 *
 * Charging either against the drawer would leave the expected balance short
 * by that amount every day, and every close would report a surplus that
 * isn't there. Both are still shown — see `staff_deductions_cents` and the
 * order figures — they just do not enter the balance.
 */
class Cashbox
{
    /**
     * A "day" here is the trading day, which need not start at midnight —
     * see BusinessDay. Only the payment timestamps are affected: expenses,
     * absences and movements carry a date somebody chose.
     */
    public function __construct(private BusinessDay $businessDay) {}

    /**
     * The whole picture for one day.
     *
     * @return array<string, mixed>
     */
    public function day(string $date): array
    {
        $opening = $this->openingCents($date);

        $collected = $this->collectedCents($date);
        $manualIn = $this->manualCents($date, CashMovement::IN);
        $cashExpenses = $this->cashExpenseCents($date);
        $manualOut = $this->manualOutExcludingExpensesCents($date);

        $staffDeductions = $this->staffDeductionCents($date);
        $billDiscounts = $this->billDiscountCents($date);

        // The one equation. Everything above feeds it; nothing else does.
        $balance = $opening + $collected + $manualIn - $cashExpenses - $manualOut;

        return [
            'date' => $date,
            'opening_cents' => $opening,

            'collected_cents' => $collected,
            'manual_in_cents' => $manualIn,
            'total_in_cents' => $collected + $manualIn,

            'expenses_cents' => $cashExpenses,
            'manual_out_cents' => $manualOut,
            'total_out_cents' => $cashExpenses + $manualOut,

            'balance_cents' => $balance,
            'net_movement_cents' => $balance - $opening,

            // Shown, never subtracted from the drawer — see the class note.
            'staff_deductions_cents' => $staffDeductions,
            'bill_discounts_cents' => $billDiscounts,

            'orders_count' => $this->paidOrders($date)->count(),
            'expenses_count' => Expense::query()->onDate($date)->count(),
            'absences_count' => Absence::query()->onDate($date)->count(),

            'closing' => $this->closingFor($date),
            'is_closed' => $this->isClosed($date),
        ];
    }

    /**
     * What was in the drawer when the day started: the previous closing's
     * *counted* figure, not its expected one.
     *
     * Carrying the expected figure forward would bury a discrepancy instead
     * of surfacing it — a till 10 short on Monday would start Tuesday
     * claiming the 10 was there, and every following day would inherit the
     * lie. Counted means tomorrow starts from the money that is actually in
     * the drawer.
     *
     * The most recent closing on or before the day is used, so a day nobody
     * closed does not reset the float to zero.
     */
    public function openingCents(string $date): int
    {
        $previous = DailyClosing::query()
            ->whereDate('business_date', '<', $date)
            ->whereNotNull('closed_at')
            ->whereNull('reopened_at')
            ->orderByDesc('business_date')
            ->first();

        return $previous?->counted_cents ?? 0;
    }

    /** Taken from customers: what the till actually received. */
    public function collectedCents(string $date): int
    {
        return (int) $this->paidOrders($date)->get()->sum(fn (Order $order) => $order->collectedCents());
    }

    /** Cash-paid expenses only. */
    public function cashExpenseCents(string $date): int
    {
        return (int) Expense::query()->onDate($date)->fromCash()->sum('amount_cents');
    }

    /** Deductions charged to employees. Never touches the drawer. */
    public function staffDeductionCents(string $date): int
    {
        return (int) Absence::query()->onDate($date)->sum('deduction_cents');
    }

    /**
     * Discounts agreed at the till. Informational only: a discounted bill
     * was collected at its discounted price, so the reduction is already
     * reflected in what came in.
     */
    public function billDiscountCents(string $date): int
    {
        return (int) $this->paidOrders($date)->sum('discount_cents');
    }

    /**
     * Whether the day is signed off. A reopened day is not closed.
     */
    public function isClosed(string $date): bool
    {
        return (bool) DailyClosing::query()
            ->whereDate('business_date', $date)
            ->whereNotNull('closed_at')
            ->whereNull('reopened_at')
            ->exists();
    }

    public function closingFor(string $date): ?DailyClosing
    {
        return DailyClosing::query()
            ->with('closedBy', 'reopenedBy')
            ->whereDate('business_date', $date)
            ->first();
    }

    /**
     * What the drawer should hold right now, which is simply the running
     * balance. Named separately because the closing screen asks the
     * question in those words and storing the answer under that name makes
     * the comparison with the counted figure obvious.
     */
    public function expectedCents(string $date): int
    {
        return $this->day($date)['balance_cents'];
    }

    /**
     * Today, in the restaurant's own timezone rather than UTC — and in its
     * own idea of a day. At 3am, in a café whose day starts at six, "today"
     * is still yesterday: the shift being closed is the one still standing
     * at the till.
     */
    public function today(): string
    {
        return $this->businessDay->today();
    }

    /**
     * Orders whose money reached the drawer on this day.
     *
     * Keyed on `paid_at`, not `created_at`. The drawer moves when cash
     * moves, and a bill opened at 23:50 and settled at 00:10 is cash that
     * physically arrives on the second day.
     *
     * Filtering by creation put it on the first, which is not a rounding
     * difference — it is money the expected balance claims is in the drawer
     * before anyone has handed it over. The day it was opened counted short
     * by the whole bill and the day it was settled counted over by the same
     * amount, so a cashier counting an honest till was told twice that it
     * was wrong. Two of the six paid orders in this install had already
     * done it.
     *
     * Days already signed off keep the figures stored on their DailyClosing
     * row, so this corrects what is still open rather than rewriting a
     * closing somebody has put their name to.
     */
    private function paidOrders(string $date)
    {
        return Order::query()
            ->whereBetween('paid_at', [
                $this->businessDay->startOf($date),
                $this->businessDay->endOf($date),
            ])
            ->where('status', '!=', 'cancelled')
            ->whereNotNull('paid_at');
    }

    private function manualCents(string $date, string $direction): int
    {
        return (int) CashMovement::query()
            ->onDate($date)
            ->where('direction', $direction)
            ->sum('amount_cents');
    }

    /**
     * Cash out that is not an expense payout.
     *
     * A cash expense writes both an Expense row and a CashMovement row —
     * the first so it appears in expense reports by category, the second so
     * the drawer sees it. Summing both would take the money out twice, so
     * the movements created by expenses are excluded here and counted via
     * `cashExpenseCents()` instead.
     */
    private function manualOutExcludingExpensesCents(string $date): int
    {
        return (int) CashMovement::query()
            ->onDate($date)
            ->outgoing()
            ->where(function ($query) {
                $query->whereNull('source_type')
                    ->orWhere('source_type', '!=', (new Expense)->getMorphClass());
            })
            ->sum('amount_cents');
    }

    /**
     * Every line behind a day's balance, in one list, each tagged with
     * where it came from.
     *
     * Lives here rather than on the cash box screen because the reports
     * need exactly the same rows over a range of days, and two builders
     * for one ledger is two ledgers: the moment one of them learns about a
     * new kind of movement and the other does not, the detail stops adding
     * up to the balance printed above it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function movements(string $date): array
    {
        // By `paid_at`, matching Cashbox::paidOrders() — this list has to add
        // up to the balance that sits above it. The row was already stamped
        // with the payment time while being selected by the creation date,
        // so a bill settled after midnight appeared on the wrong day with a
        // timestamp that contradicted the column it was filed under.
        $orders = Order::query()
            ->whereBetween('paid_at', [
                $this->businessDay->startOf($date),
                $this->businessDay->endOf($date),
            ])
            ->where('status', '!=', 'cancelled')
            ->whereNotNull('paid_at')
            ->with('paidBy')
            ->get()
            ->map(fn (Order $order) => [
                'id' => 'order-'.$order->id,
                'kind' => 'order',
                'direction' => CashMovement::IN,
                'label' => $order->reference,
                'description' => $order->table?->label ?? $order->table_number,
                'amount_cents' => $order->collectedCents(),
                'at' => $order->paid_at?->toIso8601String(),
                'by' => $order->paidBy?->name,
                'deletable' => false,
            ]);

        $expenses = Expense::query()
            ->onDate($date)
            ->fromCash()
            ->with('category', 'user', 'recordedBy')
            ->get()
            ->map(fn (Expense $expense) => [
                'id' => 'expense-'.$expense->id,
                'kind' => 'expense',
                'direction' => CashMovement::OUT,
                'label' => $expense->category?->name,
                'description' => trim($expense->reason.($expense->user ? ' — '.$expense->user->name : '')),
                'amount_cents' => $expense->amount_cents,
                'at' => $expense->created_at?->toIso8601String(),
                'by' => $expense->recordedBy?->name,
                'deletable' => false,
            ]);

        // Only the hand-entered ones: the movements an expense created are
        // already represented by the expense row above, and listing both
        // would show the same money twice.
        $manual = CashMovement::query()
            ->onDate($date)
            ->whereNull('source_type')
            ->with('recordedBy')
            ->get()
            ->map(fn (CashMovement $movement) => [
                'id' => 'movement-'.$movement->id,
                'movement_id' => $movement->id,
                'kind' => $movement->type,
                'direction' => $movement->direction,
                'label' => $movement->description,
                'description' => $movement->notes,
                'amount_cents' => $movement->amount_cents,
                'at' => $movement->created_at?->toIso8601String(),
                'by' => $movement->recordedBy?->name,
                'deletable' => true,
            ]);

        return $orders->concat($expenses)->concat($manual)
            ->sortBy('at')
            ->values()
            ->all();
    }
}
