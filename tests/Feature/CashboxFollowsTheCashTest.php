<?php

namespace Tests\Feature;

use App\Models\CashMovement;
use App\Models\Order;
use App\Models\User;
use App\Services\Accounting\Cashbox;
use App\Services\Accounting\DayCloser;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The rule the cash box rests on — "the drawer moves only when physical cash
 * moves" — checked at the places CashboxArithmeticTest does not reach.
 *
 * That file proves the arithmetic of one ordinary day, thoroughly. What had
 * nothing behind it was the machinery that carries one day into the next, and
 * the payment that arrives without settling a bill. Both are where a wrong
 * figure survives longest, because neither is visible on the day it happens:
 * the drawer is counted, it matches, and the error is in a number nobody
 * recomputes.
 */
class CashboxFollowsTheCashTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(LanguageSeeder::class);
        $this->seed(PermissionSeeder::class);
    }

    private function cashbox(): Cashbox
    {
        return app(Cashbox::class);
    }

    private function manager(): User
    {
        $user = User::create([
            'name' => 'مدير',
            'email' => 'm'.fake()->unique()->numberBetween(1, 99999).'@test.local',
            'password' => 'password',
            'locale' => 'ar',
            'email_verified_at' => now(),
        ]);
        $user->assignRole('admin');

        return $user;
    }

    private function cashier(): User
    {
        $user = User::create([
            'name' => 'كاشير',
            'email' => 'c'.fake()->unique()->numberBetween(1, 99999).'@test.local',
            'password' => 'password',
            'locale' => 'ar',
            'email_verified_at' => now(),
        ]);
        $user->assignRole('cashier');

        return $user;
    }

    /** A bill settled in full at a given moment. */
    private function paidOn(string $dateTime, int $cents): Order
    {
        return Order::create([
            'reference' => 'R-'.fake()->unique()->numberBetween(1, 999999),
            'status' => 'served',
            'total_cents' => $cents,
            'amount_paid_cents' => $cents,
            'paid_at' => $dateTime,
        ]);
    }

    // ---------------------------------------------------------------
    // Carrying the drawer from one day to the next
    // ---------------------------------------------------------------

    public function test_a_day_with_nothing_before_it_opens_empty(): void
    {
        $this->assertSame(0, $this->cashbox()->openingCents('2026-03-10'));
    }

    public function test_tomorrow_opens_on_what_was_counted_not_what_was_expected(): void
    {
        // Already asserted in CashboxArithmeticTest; repeated here as the
        // baseline the two cases below are departures from, so a failure in
        // them can be read without opening the other file.
        $this->paidOn('2026-03-10 20:00:00', 5000);
        app(DayCloser::class)->close('2026-03-10', 4800, 'ناقص 2', $this->manager());

        $this->assertSame(4800, $this->cashbox()->openingCents('2026-03-11'));
    }

    /**
     * A night nobody signed off is still carried.
     *
     * It used not to be: the float came from the last closed day and stopped
     * there, so a café that forgot to close one night was told the next night
     * that it had a surplus of exactly the forgotten day's takings — and the
     * night after that, and every night until somebody closed on a real count
     * and absorbed it. A whole day's money, reported as a discrepancy in the
     * till rather than as the missing close it was.
     */
    public function test_a_day_nobody_closed_is_still_carried_into_the_next_opening(): void
    {
        $this->paidOn('2026-03-10 20:00:00', 5000);
        app(DayCloser::class)->close('2026-03-10', 5000, null, $this->manager());

        // The 11th takes 3000 and is never closed.
        $this->paidOn('2026-03-11 20:00:00', 3000);

        // The drawer physically holds 8000 on the morning of the 12th, and
        // now says so.
        $this->assertSame(8000, $this->cashbox()->openingCents('2026-03-12'));
        $this->assertSame(8000, $this->cashbox()->day('2026-03-12')['balance_cents']);
    }

    /**
     * Several skipped nights compound rather than only the last one counting.
     */
    public function test_a_run_of_unclosed_nights_all_carry(): void
    {
        $this->paidOn('2026-03-10 20:00:00', 5000);
        app(DayCloser::class)->close('2026-03-10', 5000, null, $this->manager());

        $this->paidOn('2026-03-11 20:00:00', 3000);
        $this->paidOn('2026-03-12 20:00:00', 2000);
        $this->paidOn('2026-03-13 20:00:00', 1000);

        $this->assertSame(11000, $this->cashbox()->openingCents('2026-03-14'));
    }

    /**
     * What went *out* on a skipped night is carried too — a float that only
     * ever grew would be worse than one that never moved.
     */
    public function test_money_paid_out_on_a_skipped_night_is_carried_as_well(): void
    {
        $this->paidOn('2026-03-10 20:00:00', 5000);
        app(DayCloser::class)->close('2026-03-10', 5000, null, $this->manager());

        $this->paidOn('2026-03-11 14:00:00', 3000);

        CashMovement::create([
            'direction' => CashMovement::OUT,
            'type' => CashMovement::TYPE_WITHDRAWAL,
            'amount_cents' => 2500,
            'occurred_on' => '2026-03-11',
            'description' => 'إيداع في الخزنة',
        ]);

        // 5000 counted + 3000 taken − 2500 withdrawn.
        $this->assertSame(5500, $this->cashbox()->openingCents('2026-03-12'));
    }

    /**
     * An install where nobody has ever closed a day.
     *
     * The same statement with nothing to subtract: the drawer holds
     * everything it ever took, less everything it ever paid out. Reporting
     * zero there — which is what it used to do — told a café running for a
     * month that its float was empty every single morning.
     */
    public function test_with_no_closing_anywhere_the_float_is_the_whole_history(): void
    {
        $this->paidOn('2026-03-08 20:00:00', 4000);
        $this->paidOn('2026-03-09 20:00:00', 2500);

        $this->assertSame(6500, $this->cashbox()->openingCents('2026-03-10'));
    }

    /**
     * Closing the skipped night takes over from the carry rather than adding
     * to it — the count is the whole answer for every day before it.
     */
    public function test_closing_the_skipped_night_replaces_the_carry(): void
    {
        $this->paidOn('2026-03-10 20:00:00', 5000);
        app(DayCloser::class)->close('2026-03-10', 5000, null, $this->manager());

        $this->paidOn('2026-03-11 20:00:00', 3000);
        $this->assertSame(8000, $this->cashbox()->openingCents('2026-03-12'));

        // Counted 200 short on the night that had been skipped.
        app(DayCloser::class)->close('2026-03-11', 7800, 'عُدّ متأخراً', $this->manager());

        $this->assertSame(7800, $this->cashbox()->openingCents('2026-03-12'), 'not 7800 + 3000');
    }

    /**
     * Reopening the 10th puts the 11th back on the running total.
     *
     * openingCents() only trusts a closing that is currently signed off, and
     * a reopened one is not — so the float falls back to what actually moved
     * through the drawer, exactly as it does for a night nobody closed at
     * all. The counted figure stops applying because nobody is currently
     * standing behind it.
     *
     * The two figures are deliberately different here: the night was counted
     * 200 short, so 4800 can only come from the closing and 5000 can only
     * come from the carry. Closing on a matching count would prove nothing
     * about which one is being read.
     *
     * This used to answer zero — reopening a night to correct it silently
     * emptied the float of every day after it.
     */
    public function test_reopening_a_night_puts_the_next_day_back_on_the_running_total(): void
    {
        $this->paidOn('2026-03-10 20:00:00', 5000);
        app(DayCloser::class)->close('2026-03-10', 4800, 'ناقص 2', $this->manager());

        $this->assertSame(4800, $this->cashbox()->openingCents('2026-03-11'), 'the counted figure, while it stands');

        app(DayCloser::class)->reopen('2026-03-10', $this->manager());

        $this->assertFalse($this->cashbox()->isClosed('2026-03-10'));
        $this->assertSame(5000, $this->cashbox()->openingCents('2026-03-11'), 'what actually moved, now nobody stands behind the count');
    }

    public function test_closing_it_again_restores_the_float(): void
    {
        $this->paidOn('2026-03-10 20:00:00', 5000);
        app(DayCloser::class)->close('2026-03-10', 5000, null, $this->manager());
        app(DayCloser::class)->reopen('2026-03-10', $this->manager());
        app(DayCloser::class)->close('2026-03-10', 4900, 'أُعيد العدّ', $this->manager());

        $this->assertTrue($this->cashbox()->isClosed('2026-03-10'));
        $this->assertSame(4900, $this->cashbox()->openingCents('2026-03-11'));
    }

    /**
     * The float comes from the nearest signed-off night, not the first one.
     */
    public function test_the_most_recent_close_wins_over_an_older_one(): void
    {
        $this->paidOn('2026-03-08 20:00:00', 1000);
        app(DayCloser::class)->close('2026-03-08', 1000, null, $this->manager());

        $this->paidOn('2026-03-09 20:00:00', 2000);
        app(DayCloser::class)->close('2026-03-09', 3000, null, $this->manager());

        $this->assertSame(3000, $this->cashbox()->openingCents('2026-03-10'));
    }

    public function test_a_closing_after_the_day_does_not_become_its_float(): void
    {
        // Guards the boundary in openingCents()'s `<` — a night closed
        // *after* the day being viewed says nothing about how that day began.
        $this->paidOn('2026-03-12 20:00:00', 9000);
        app(DayCloser::class)->close('2026-03-12', 9000, null, $this->manager());

        $this->assertSame(0, $this->cashbox()->openingCents('2026-03-11'));
    }

    // ---------------------------------------------------------------
    // Money handed over without settling the bill
    // ---------------------------------------------------------------

    /**
     * A part-payment reaches the drawer's figures on the day it arrives.
     *
     * This used to be the other way round, and it was the one defect in here
     * that moved real money. markPaid() recorded the amount on the bill and
     * opened the drawer — its own comment says "a part-payment is still cash
     * crossing the counter" — while leaving `paid_at` null, and the cash box
     * selected on `paid_at`. Fifty crossed the counter and no figure
     * describing the counter knew about it.
     *
     * The fix is the order_payments table: what changed hands, and when. See
     * that migration for why a running total could never answer this.
     */
    public function test_a_part_payment_reaches_the_cash_box_the_day_it_arrives(): void
    {
        $order = Order::create([
            'reference' => 'R-'.fake()->unique()->numberBetween(1, 999999),
            'status' => 'served',
            'total_cents' => 10000,
        ]);

        $today = $this->cashbox()->today();

        $this->actingAs($this->cashier())
            ->patch(route('orders.pay', $order->id), ['amount_paid_cents' => 5000, 'partial' => true])
            ->assertSessionHasNoErrors();

        $this->assertSame(5000, $order->fresh()->amount_paid_cents, 'the money is recorded on the bill');
        $this->assertNull($order->fresh()->paid_at, 'and the bill is still open');

        // 5000 crossed the counter, and the drawer says so.
        $this->assertSame(5000, $this->cashbox()->day($today)['collected_cents']);
        $this->assertSame(5000, $this->cashbox()->day($today)['balance_cents']);
    }

    /**
     * Settling a bill collects only the part that arrives now — the earlier
     * instalment stays on the day it was handed over.
     *
     * Within one day the old behaviour's two errors cancelled and nobody
     * noticed, which is why it survived. Across a close they did not: the
     * night the half arrived counted short by it, and the night the rest
     * arrived counted over by the same amount.
     */
    public function test_each_instalment_lands_on_its_own_day(): void
    {
        $order = Order::create([
            'reference' => 'R-'.fake()->unique()->numberBetween(1, 999999),
            'status' => 'served',
            'total_cents' => 10000,
        ]);

        $cashier = $this->cashier();

        // Half tonight.
        $this->travelTo('2026-03-10 22:00:00');
        $this->actingAs($cashier)->patch(route('orders.pay', $order->id), ['amount_paid_cents' => 5000, 'partial' => true]);

        // The rest tomorrow.
        $this->travelTo('2026-03-11 13:00:00');
        $this->actingAs($cashier)->patch(route('orders.pay', $order->id), ['amount_paid_cents' => 5000, 'partial' => true]);
        $this->travelBack();

        $this->assertNotNull($order->fresh()->paid_at, 'covering the bill settles it');
        $this->assertSame(10000, $order->fresh()->amount_paid_cents, 'and the bill has taken all of it');

        $this->assertSame(5000, $this->cashbox()->day('2026-03-10')['collected_cents'], 'the night the half arrived');
        $this->assertSame(5000, $this->cashbox()->day('2026-03-11')['collected_cents'], 'the day the rest did');
    }

    /**
     * A final settle after instalments records what changed hands now, not
     * the total the till typed.
     *
     * The till states a settle as a total — "this bill has now taken 100" —
     * so taking that figure as the payment would put the earlier fifty in the
     * drawer a second time.
     */
    public function test_settling_in_full_after_an_instalment_banks_only_the_remainder(): void
    {
        $order = Order::create([
            'reference' => 'R-'.fake()->unique()->numberBetween(1, 999999),
            'status' => 'served',
            'total_cents' => 10000,
        ]);

        $cashier = $this->cashier();
        $today = $this->cashbox()->today();

        $this->actingAs($cashier)->patch(route('orders.pay', $order->id), ['amount_paid_cents' => 4000, 'partial' => true]);
        $this->actingAs($cashier)->patch(route('orders.pay', $order->id), ['amount_paid_cents' => 10000]);

        $this->assertSame(10000, $order->fresh()->amount_paid_cents);
        $this->assertSame(10000, $this->cashbox()->day($today)['collected_cents'], 'not 14000');
        $this->assertSame(
            10000,
            $order->fresh()->payments()->sum('amount_cents'),
            'the timeline adds up to the bill'
        );
    }

    /**
     * The invariant the two writes exist to keep: the handovers add up to
     * what the bill says it has taken.
     */
    public function test_the_payment_timeline_always_adds_up_to_the_bill(): void
    {
        $order = Order::create([
            'reference' => 'R-'.fake()->unique()->numberBetween(1, 999999),
            'status' => 'served',
            'total_cents' => 9000,
        ]);

        $cashier = $this->cashier();

        foreach ([2000, 3000, 1500] as $instalment) {
            $this->actingAs($cashier)->patch(route('orders.pay', $order->id), [
                'amount_paid_cents' => $instalment, 'partial' => true,
            ]);
        }

        $order->refresh();

        $this->assertSame(6500, $order->amount_paid_cents);
        $this->assertSame(6500, (int) $order->payments()->sum('amount_cents'));
        $this->assertSame(3, $order->payments()->count(), 'one row per handover');
        $this->assertSame(2500, $order->outstandingCents());
    }

    /**
     * Voiding a table takes its money back out of the day, however it was
     * paid — the guard has to survive the move to a payments timeline.
     */
    public function test_cancelling_a_part_paid_table_removes_it_from_the_day(): void
    {
        $order = Order::create([
            'reference' => 'R-'.fake()->unique()->numberBetween(1, 999999),
            'status' => 'served',
            'total_cents' => 10000,
        ]);

        $today = $this->cashbox()->today();

        $this->actingAs($this->cashier())
            ->patch(route('orders.pay', $order->id), ['amount_paid_cents' => 5000, 'partial' => true]);

        $this->assertSame(5000, $this->cashbox()->day($today)['collected_cents']);

        $order->update(['status' => 'cancelled']);

        $this->assertSame(0, $this->cashbox()->day($today)['collected_cents']);
    }

    /**
     * Rounding down at the till is collected at what was actually taken.
     */
    public function test_the_drawer_counts_what_was_taken_not_what_was_billed(): void
    {
        $this->paidOn('2026-03-10 20:00:00', 10000)->update(['amount_paid_cents' => 9800]);

        $this->assertSame(9800, $this->cashbox()->day('2026-03-10')['collected_cents']);
    }

    /**
     * A tip, or a customer who insists on rounding up, is money in the
     * drawer and is counted as such.
     */
    public function test_more_than_the_bill_is_still_what_is_in_the_drawer(): void
    {
        $order = $this->paidOn('2026-03-10 20:00:00', 10000);
        $order->update(['amount_paid_cents' => 11000]);

        $this->assertSame(11000, $this->cashbox()->day('2026-03-10')['collected_cents']);
        $this->assertSame(0, $order->fresh()->outstandingCents(), 'an overpaid bill owes nothing');
    }

    /**
     * A bill that was never paid is not money, whatever its total says.
     */
    public function test_an_open_bill_is_not_in_the_drawer(): void
    {
        Order::create([
            'reference' => 'R-'.fake()->unique()->numberBetween(1, 999999),
            'status' => 'served',
            'total_cents' => 7000,
        ]);

        $this->assertSame(0, $this->cashbox()->day($this->cashbox()->today())['collected_cents']);
    }

    /**
     * A cancelled bill is left out even when it carries a payment stamp —
     * the guard that keeps a voided table out of the day's takings.
     */
    public function test_a_cancelled_bill_is_left_out(): void
    {
        $order = $this->paidOn('2026-03-10 20:00:00', 4000);
        $order->update(['status' => 'cancelled']);

        $this->assertSame(0, $this->cashbox()->day('2026-03-10')['collected_cents']);
    }

    // ---------------------------------------------------------------
    // The ledger under the balance
    // ---------------------------------------------------------------

    /**
     * The detail list and the headline figure are built by different queries,
     * and the whole reason movements() lives beside day() is that they must
     * not disagree. Nothing asserted that they don't.
     */
    public function test_the_listed_lines_add_up_to_the_balance_above_them(): void
    {
        $this->paidOn('2026-03-10 12:00:00', 3000);
        $this->paidOn('2026-03-10 19:30:00', 4500);

        $day = $this->cashbox()->day('2026-03-10');
        $lines = $this->cashbox()->movements('2026-03-10');

        $in = collect($lines)->where('direction', 'in')->sum('amount_cents');
        $out = collect($lines)->where('direction', 'out')->sum('amount_cents');

        $this->assertSame($day['balance_cents'], $day['opening_cents'] + $in - $out);
    }

    public function test_the_ledger_is_ordered_by_when_the_money_moved(): void
    {
        $this->paidOn('2026-03-10 19:30:00', 4500);
        $this->paidOn('2026-03-10 12:00:00', 3000);

        $times = array_column($this->cashbox()->movements('2026-03-10'), 'at');
        $sorted = $times;
        sort($sorted);

        $this->assertSame($sorted, $times);
    }
}
