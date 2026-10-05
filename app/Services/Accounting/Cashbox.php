<?php

namespace App\Services\Accounting;

use App\Models\Absence;
use App\Models\CashMovement;
use App\Models\DailyClosing;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Settings\RestaurantSettings;

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
 *
 * ## Card payments
 *
 * A bill settled at the card terminal is the same rule pointed at real
 * takings rather than at a deduction: the café earned the money and the
 * drawer never saw it. It is money owed to the café by the card processor,
 * not money a cashier can count, and it lands in the bank days later.
 *
 * So `collected_cents` — the figure that enters the balance and the one a
 * closing is signed against — is *cash* taken from customers, and card
 * takings are reported alongside it as `card_collected_cents`. Counting
 * cards into the drawer would report a shortfall equal to the day's card
 * trade, every day, against a till that was counted correctly, and the
 * cashier would be told they were short by money nobody was ever handed.
 */
class Cashbox
{
    /**
     * A "day" here is the trading day, which need not start at midnight —
     * see BusinessDay. Only the payment timestamps are affected: expenses,
     * absences and movements carry a date somebody chose.
     */
    public function __construct(
        private BusinessDay $businessDay,
        private RestaurantSettings $settings,
    ) {}

    /**
     * The whole picture for one day.
     *
     * @return array<string, mixed>
     */
    public function day(string $date): array
    {
        $opening = $this->openingCents($date);

        $collected = $this->collectedCents($date);
        $cardCollected = $this->cardCollectedCents($date);
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

            // Shown, never added to or subtracted from the drawer — see the
            // class note. Card takings are the café's money and appear in the
            // revenue reports; they are simply not in the till.
            'card_collected_cents' => $cardCollected,
            'total_takings_cents' => $collected + $cardCollected,

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
     * What was in the drawer when this day started: the float the owner
     * leaves behind every night, and nothing else.
     *
     * The café's routine is that the till opens by itself on a fixed sum of
     * change — 500 unless the settings say otherwise — and the one thing the
     * owner does is close it: count the drawer, take everything above the
     * float, leave the float for the morning. So the morning is a policy
     * rather than a derivation, and the screen states the policy.
     *
     * It used to be derived: last night's *counted* figure, plus whatever
     * moved on any night nobody signed off since. That is the right answer
     * for a drawer nobody empties and the wrong one for this café's. A night
     * counted at 1,840 opened the next day at 1,840 after the owner had
     * pocketed 1,340 of it, and an install that had never been closed opened
     * every morning on its entire takings to date — a number that grew by a
     * day's trade each day and described no drawer anyone had looked into.
     *
     * What that gives up: a night that was never closed no longer carries
     * into the next morning. If its money really was left in the drawer, the
     * next close reports it as a surplus; closing the skipped night first, or
     * recording the extra as a float top-up, puts the figures back in step.
     *
     * Read live from the settings rather than stored per day, so a change of
     * float reaches every day still open. Closed days keep the opening they
     * were signed against — DayCloser snapshots it.
     */
    public function openingCents(string $date): int
    {
        return max(0, $this->settings->cashbox_float_cents);
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
     *
     * Cash only. This is the figure the balance is built from and the one a
     * cashier signs a count against, and a card payment never reached the
     * drawer to be counted — see the class note. Card takings are
     * cardCollectedCents(), reported beside this and never inside it.
     */
    public function collectedCents(string $date): int
    {
        return $this->collectedByMethodCents($date, cash: true);
    }

    /**
     * Taken from customers on a card: real revenue, not in the drawer.
     *
     * Reported so that a manager reading the cash box still sees the day's
     * whole trade — a café whose card terminal took half the evening would
     * otherwise find a takings figure that looks like a collapse in business
     * rather than a change in how people paid.
     */
    public function cardCollectedCents(string $date): int
    {
        return $this->collectedByMethodCents($date, cash: false);
    }

    /**
     * The two halves of a day's takings, split by whether the drawer moved.
     *
     * One query shape for both so the halves cannot drift apart: they are
     * added together and shown as the day's total trade, and a difference in
     * how each side treats a cancelled bill or a trading-day boundary would
     * make that total disagree with itself.
     */
    private function collectedByMethodCents(string $date, bool $cash): int
    {
        return (int) OrderPayment::query()
            ->when($cash, fn ($query) => $query->cash(), fn ($query) => $query->card())
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
     * Everything that was sold today, one line per dish, with the quantity
     * that went out and what it brought in.
     *
     * The question this answers is not the drawer's — it is "how did the
     * night go, and on what". A manager holding the count also wants to know
     * that forty-one teas went out and that the kitchen sold three of the new
     * dish, and until now that meant opening the reports on a screen while
     * standing at the printer.
     *
     * Read off the bills settled today — the same set `billDiscountCents()`
     * uses — rather than off the payment timeline, because a dish belongs to
     * the bill it was ordered on and a bill is the only thing that carries
     * lines at all. A table still open at close is therefore not here; its
     * dishes land on the day its bill is settled, which is the same day its
     * discount lands, so the two figures cannot disagree.
     *
     * `net_cents` is what the bills came to, and it is deliberately not
     * reconciled against what the drawer took: a till that rounded a bill
     * down, or a table halfway through paying, makes those two different
     * numbers describing different things. This one is trade.
     *
     * @return array<string, mixed>
     */
    public function itemsSold(string $date): array
    {
        $lines = OrderItem::query()
            ->whereIn('order_id', $this->paidOrders($date)->select('id'))
            // Grouped on the name copied onto the line, not on the product,
            // because that name is what was sold: a product renamed or
            // deleted since still reads on this paper the way it read on the
            // bill, and a service charge has no product to group by at all.
            ->selectRaw('product_name, MAX(service_charge_id) as service_charge_id, SUM(quantity) as quantity, SUM(subtotal_cents) as total_cents')
            ->groupBy('product_name')
            ->get()
            ->map(fn (OrderItem $row) => [
                'name' => $row->product_name,
                'quantity' => (int) $row->quantity,
                'total_cents' => (int) $row->total_cents,
                'is_service' => $row->service_charge_id !== null,
            ])
            // Busiest first, which is the order somebody scanning the list is
            // reading for. Service charges sink to the bottom regardless:
            // they are money on the bill with nothing behind them, and a
            // cover charge sitting at the top of a list headed "sold" reads
            // like the best-selling item of the night.
            ->sortBy([
                fn (array $a, array $b) => $a['is_service'] <=> $b['is_service'],
                fn (array $a, array $b) => $b['quantity'] <=> $a['quantity'],
                fn (array $a, array $b) => $a['name'] <=> $b['name'],
            ])
            ->values();

        $discounts = $this->billDiscountCents($date);
        $gross = (int) $lines->sum('total_cents');

        return [
            'lines' => $lines->all(),
            // Things actually served. A service charge is on the bill and in
            // the money, but counting it here would inflate "47 items" with
            // lines nobody carried to a table.
            'quantity' => (int) $lines->reject(fn (array $line) => $line['is_service'])->sum('quantity'),
            'gross_cents' => $gross,
            'discount_cents' => $discounts,
            'net_cents' => $gross - $discounts,
        ];
    }

    /**
     * Every bill money came from today, one line each, with how it was paid —
     * and the day's takings split by method underneath.
     *
     * The question is the drawer's again rather than the kitchen's: "which
     * tables paid, how, and does it add up". So it is read off the payment
     * timeline, the same rows collectedCents() and cardCollectedCents() sum,
     * and not off the bills settled today the way itemsSold() is. That is
     * what lets the totals under this list be the very figures printed at
     * the top of the paper — cash here is "cash from orders", card here is
     * "card takings" — instead of two numbers a manager has to explain to
     * each other.
     *
     * The cost is that a line is what arrived *today*, not what the bill came
     * to. For a table that pays once those are the same, which is nearly all
     * of them. A table paying in instalments shows each night's part on that
     * night, flagged as a part payment wherever the bill did not settle on
     * the day — a 15.00 line with nothing beside it would read as a 15.00
     * bill. A bill that came to nothing has no handover and so no line; it
     * is still among the bills counted under what was sold.
     *
     * @return array<string, mixed>
     */
    public function ordersCollected(string $date): array
    {
        $start = $this->businessDay->startOf($date);
        $end = $this->businessDay->endOf($date);

        $payments = OrderPayment::query()
            ->whereBetween('received_at', [$start, $end])
            ->whereHas('order', fn ($query) => $query->where('status', '!=', 'cancelled'))
            ->with('order.table')
            ->orderBy('received_at')
            ->orderBy('id')
            ->get();

        $lines = $payments
            ->groupBy('order_id')
            ->map(function ($handovers) use ($start, $end) {
                /** @var Order $order */
                $order = $handovers->first()->order;

                $byMethod = $handovers
                    ->groupBy('method')
                    ->map(fn ($group) => (int) $group->sum('amount_cents'))
                    ->filter(fn (int $cents) => $cents !== 0)
                    ->all();

                $total = (int) $handovers->sum('amount_cents');

                return [
                    'reference' => $order->reference,
                    'table' => $order->table?->label ?? $order->table_number,
                    // The last handover of the day: for a bill paid once it
                    // is the moment it was paid, and for one paid in parts it
                    // is the moment it was done with for the night.
                    'at' => $handovers->last()->received_at,
                    // One method, or null when the money came more than one
                    // way and `by_method` has to be read to say how.
                    'method' => count($byMethod) === 1 ? array_key_first($byMethod) : null,
                    'by_method' => $byMethod,
                    'total_cents' => $total,
                    'card_references' => $handovers
                        ->where('method', OrderPayment::METHOD_CARD)
                        ->pluck('reference')
                        ->filter()
                        ->unique()
                        ->values()
                        ->all(),
                    'is_refund' => $total < 0,
                    'is_part_payment' => $total > 0
                        && ($order->paid_at === null || ! $order->paid_at->between($start, $end)),
                ];
            })
            ->sortBy('at')
            ->values();

        // Every known method, zero or not. On a summary "card 0.00" is an
        // answer — no card trade tonight — and a line that silently goes
        // missing is the one nobody notices.
        $byMethod = collect(OrderPayment::methods())
            ->mapWithKeys(fn (string $method) => [
                $method => (int) $payments->where('method', $method)->sum('amount_cents'),
            ])
            ->all();

        return [
            'lines' => $lines->all(),
            'by_method' => $byMethod,
            'total_cents' => (int) $payments->sum('amount_cents'),
        ];
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
        //
        // Cash only, for that same reason. A card payment is not a movement
        // of the drawer, so a line for it here would be a line the balance
        // does not contain — and this list is read precisely when the balance
        // is being questioned, which is the worst moment to hand somebody
        // rows that do not add up to it.
        $orders = OrderPayment::query()
            ->cash()
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
                'description' => collect([$expense->reason, $expense->user?->name])->filter()->implode(' — '),
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
