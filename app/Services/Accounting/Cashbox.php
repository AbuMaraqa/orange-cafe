<?php

namespace App\Services\Accounting;

use App\Models\Absence;
use App\Models\CashMovement;
use App\Models\DailyClosing;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderPayment;
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
     * What was in the drawer when this day started.
     *
     * Two parts, and both are needed:
     *
     *  1. the last signed-off count — the *counted* figure, never the
     *     expected one. Carrying the expected figure forward would bury a
     *     discrepancy instead of surfacing it: a till 10 short on Monday
     *     would start Tuesday claiming the 10 was there, and every following
     *     day would inherit the lie. Counted means tomorrow starts from the
     *     money that is actually in the drawer.
     *
     *  2. everything that moved through the drawer on the days in between.
     *
     * The second part used to be missing, and it is the reason a café that
     * forgot to close one night was told it had a surplus — every night after
     * it, until somebody closed on a real count and absorbed the difference.
     * Monday counted 50, Tuesday took 30 and nobody signed it off, and
     * Wednesday opened claiming 50 while the drawer held 80. Nothing about
     * that is a rounding difference; it is a whole night's takings, reported
     * as a discrepancy in the till rather than as the missing close it is.
     *
     * With no closing anywhere behind it the float is the whole history,
     * which is the same statement with nothing to subtract: an install where
     * nobody has ever closed a day has a drawer holding everything it ever
     * took, less everything it ever paid out.
     */
    public function openingCents(string $date): int
    {
        $previous = DailyClosing::query()
            ->whereDate('business_date', '<', $date)
            ->whereNotNull('closed_at')
            ->whereNull('reopened_at')
            ->orderByDesc('business_date')
            ->first();

        $counted = $previous?->counted_cents ?? 0;

        // The days the count does not already account for: everything after
        // the night that was signed off, up to the night before this one.
        $from = $previous
            ? Carbon::parse($previous->business_date)->addDay()->toDateString()
            : null;
        $to = Carbon::parse($date)->subDay()->toDateString();

        // Closed yesterday and asking about today — the usual case, and there
        // is nothing in between to carry.
        if ($from !== null && $from > $to) {
            return $counted;
        }

        return $counted + $this->netMovementBetween($from, $to);
    }

    /**
     * What the drawer gained or lost across a run of days, by the same terms
     * the daily balance is built from.
     *
     * Deliberately assembled out of the same four figures rather than by
     * looping over day() — a day at a time would re-run openingCents() for
     * each one, which is what calls this.
     *
     * @param  string|null  $from  null means "from the beginning", which is
     *                             the case where no day has ever been closed
     */
    private function netMovementBetween(?string $from, string $to): int
    {
        $collected = (int) OrderPayment::query()
            ->when($from !== null, fn ($q) => $q->where('received_at', '>=', $this->businessDay->startOf($from)))
            ->where('received_at', '<=', $this->businessDay->endOf($to))
            ->whereHas('order', fn ($query) => $query->where('status', '!=', 'cancelled'))
            ->sum('amount_cents');

        // Expenses, absences and hand-entered movements carry a date somebody
        // typed rather than a moment to be classified, so they are bounded by
        // that date and not by the trading-day window. Same split as day().
        $inRange = fn ($query) => $query
            ->when($from !== null, fn ($q) => $q->whereDate('occurred_on', '>=', $from))
            ->whereDate('occurred_on', '<=', $to);

        $manualIn = (int) CashMovement::query()
            ->where('direction', CashMovement::IN)
            ->tap($inRange)
            ->sum('amount_cents');

        $manualOut = (int) CashMovement::query()
            ->outgoing()
            ->where(function ($query) {
                $query->whereNull('source_type')
                    ->orWhere('source_type', '!=', (new Expense)->getMorphClass());
            })
            ->tap($inRange)
            ->sum('amount_cents');

        $expenses = (int) Expense::query()
            ->fromCash()
            ->when($from !== null, fn ($q) => $q->whereDate('spent_on', '>=', $from))
            ->whereDate('spent_on', '<=', $to)
            ->sum('amount_cents');

        return $collected + $manualIn - $expenses - $manualOut;
    }

    /**
     * Taken from customers: what the till actually received, on the day it
     * received it.
     *
     * Summed from `order_payments` — the record of each handover — rather
     * than from the bills settled today. The two are the same figure for a
     * table that pays once, which is nearly all of them, and they are not the
     * same for a table that pays in instalments: a bill half-paid tonight and
     * settled tomorrow put money in the drawer tonight.
     *
     * Read off the bill's own running total instead, as this did, and that
     * money is in the drawer and in no figure describing the drawer — then
     * the whole bill lands on the day it settles, including the half that
     * arrived the night before. The night it arrived counts short by it and
     * the night it settles counts over by the same amount: the identical
     * failure this class already fixed for `created_at` versus `paid_at`, and
     * the reason the moments are now recorded rather than inferred.
     *
     * Cancelled bills are excluded here and not at write time, so voiding a
     * table takes its money out of the day exactly the way paidOrders() does.
     */
    public function collectedCents(string $date): int
    {
        return (int) OrderPayment::query()
            ->whereBetween('received_at', [
                $this->businessDay->startOf($date),
                $this->businessDay->endOf($date),
            ])
            ->whereHas('order', fn ($query) => $query->where('status', '!=', 'cancelled'))
            ->sum('amount_cents');
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
        // One line per handover, matching collectedCents() — this list has to
        // add up to the balance that sits above it, and a bill paid in two
        // goes into the drawer twice, on two different days.
        //
        // Listing settled bills instead put a table's whole total on the
        // night it closed, so a manager tracing an evening that was fifty
        // over found a list that agreed with the wrong figure.
        $orders = OrderPayment::query()
            ->whereBetween('received_at', [
                $this->businessDay->startOf($date),
                $this->businessDay->endOf($date),
            ])
            ->whereHas('order', fn ($query) => $query->where('status', '!=', 'cancelled'))
            ->with('order.table', 'receivedBy')
            ->get()
            ->map(fn (OrderPayment $payment) => [
                'id' => 'payment-'.$payment->id,
                'kind' => 'order',
                // Negative only when a bill was corrected downwards and money
                // went back across the counter, which is cash leaving.
                'direction' => $payment->amount_cents < 0 ? CashMovement::OUT : CashMovement::IN,
                'label' => $payment->order?->reference,
                'description' => $payment->order?->table?->label ?? $payment->order?->table_number,
                'amount_cents' => abs($payment->amount_cents),
                'at' => $payment->received_at?->toIso8601String(),
                'by' => $payment->receivedBy?->name,
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
