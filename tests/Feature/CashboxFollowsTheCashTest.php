<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\Accounting\Cashbox;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsCashboxFloat;
use Tests\TestCase;

/**
 * The rule the cash box rests on — "the drawer moves only when physical cash
 * moves" — checked at the places CashboxArithmeticTest does not reach.
 *
 * That file proves the arithmetic of one ordinary day, thoroughly. What had
 * nothing behind it was the payment that arrives without settling a bill —
 * where a wrong figure survives longest, because it is not visible on the day
 * it happens: the drawer is counted, it matches, and the error is in a number
 * nobody recomputes.
 *
 * How one day hands over to the next is CashboxOpensOnTheFloatTest.
 */
class CashboxFollowsTheCashTest extends TestCase
{
    use RefreshDatabase, SetsCashboxFloat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(LanguageSeeder::class);
        $this->seed(PermissionSeeder::class);

        // Against an empty drawer, so a balance below is only the cash that
        // moved — see SetsCashboxFloat.
        $this->floatOf(0);
    }

    private function cashbox(): Cashbox
    {
        return app(Cashbox::class);
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
