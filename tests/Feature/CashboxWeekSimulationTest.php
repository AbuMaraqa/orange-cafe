<?php

namespace Tests\Feature;

use App\Models\AbsenceCategory;
use App\Models\CashMovement;
use App\Models\ExpenseCategory;
use App\Models\Order;
use App\Models\User;
use App\Services\Accounting\AbsenceRecorder;
use App\Services\Accounting\Cashbox;
use App\Services\Accounting\DayCloser;
use App\Services\Accounting\ExpenseRecorder;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsCashboxFloat;
use Tests\TestCase;

/**
 * Four trading days run end to end, as a café would actually run them.
 *
 * CashboxArithmeticTest asserts each rule in isolation, which is the right
 * shape for a rule and the wrong shape for a drawer: every one of those tests
 * starts from an empty till, and the failures this subsystem is most exposed
 * to only appear once days follow one another — a night closed short, a day
 * reopened, an expense corrected after the fact.
 *
 * Every day here opens on the float, 500, the way the café runs: the owner
 * closes at night, takes everything above it, and leaves it for the morning.
 *
 * So this does not test a rule. It runs a week — takings, a cash expense, an
 * advance to a waiter, an absence deduction, a refund, a float top-up, a
 * withdrawal to the safe, a bill discount, an expense edited after the fact,
 * a night closed short and a night closed over, and a day reopened and closed
 * again — and after every single step it asserts the same three things:
 *
 *   1. the balance equals the one equation the Cashbox class is built on;
 *   2. the movement list adds up to the balance printed above it;
 *   3. nothing that is not physical cash has moved the drawer.
 *
 * A café's real complaint is never "collectedCents is wrong". It is "the till
 * has been forty short every night since Tuesday", and that is what this is
 * shaped to catch.
 */
class CashboxWeekSimulationTest extends TestCase
{
    use RefreshDatabase, SetsCashboxFloat;

    /** What every morning opens on — see Cashbox::openingCents(). */
    private const FLOAT = self::DEFAULT_FLOAT_CENTS;

    private const MON = '2026-03-02';

    private const TUE = '2026-03-03';

    private const WED = '2026-03-04';

    private const THU = '2026-03-05';

    private User $owner;

    private User $waiter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(LanguageSeeder::class);
        $this->seed(PermissionSeeder::class);

        $this->owner = $this->staff('صاحب المحل', 'admin');
        $this->waiter = $this->staff('أحمد', 'waiter');
    }

    private function staff(string $name, string $role): User
    {
        $user = User::create([
            'name' => $name,
            'email' => $role.fake()->unique()->numberBetween(1, 99999).'@test.local',
            'password' => 'password',
            'locale' => 'ar',
            'email_verified_at' => now(),
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function cashbox(): Cashbox
    {
        return app(Cashbox::class);
    }

    private function sale(string $at, int $cents, int $discountCents = 0): Order
    {
        return Order::create([
            'reference' => 'R-'.fake()->unique()->numberBetween(1, 999999),
            'status' => 'served',
            'total_cents' => $cents,
            'discount_cents' => $discountCents,
            'amount_paid_cents' => $cents,
            'paid_at' => $at,
        ]);
    }

    private function cashExpense(string $date, int $cents, string $reason, ?int $userId = null)
    {
        return app(ExpenseRecorder::class)->create([
            'expense_category_id' => ExpenseCategory::firstOrCreate(['name' => 'مشتريات'])->id,
            'user_id' => $userId,
            'amount_cents' => $cents,
            'spent_on' => $date,
            'reason' => $reason,
            'paid_from_cash' => true,
        ], recordedBy: $this->owner->id);
    }

    private function movement(string $date, string $direction, string $type, int $cents, string $description): void
    {
        CashMovement::create([
            'direction' => $direction,
            'type' => $type,
            'amount_cents' => $cents,
            'occurred_on' => $date,
            'description' => $description,
            'recorded_by' => $this->owner->id,
        ]);
    }

    /**
     * The invariants, asserted after every step of the week.
     *
     * Split out rather than repeated so that adding a step to the story
     * cannot accidentally skip one of them — which is exactly how a
     * simulation quietly stops proving anything.
     */
    private function assertDayHolds(string $date, string $context): void
    {
        $day = $this->cashbox()->day($date);

        // 1. The one equation, restated independently of the code that
        //    produced it. If Cashbox ever grows a term that belongs in the
        //    balance, this fails until it is added here too — deliberately,
        //    because a new kind of movement silently missing from the
        //    balance is the whole failure mode.
        $this->assertSame(
            $day['opening_cents']
                + $day['collected_cents']
                + $day['manual_in_cents']
                - $day['expenses_cents']
                - $day['manual_out_cents'],
            $day['balance_cents'],
            "[$context] the balance is not the sum of its parts"
        );

        // 2. The detail under the headline. These are built by different
        //    queries and must never disagree — a manager tracing a wrong
        //    balance reads the list, and a list that does not add up sends
        //    them looking for a movement that does not exist.
        $lines = $this->cashbox()->movements($date);
        $in = collect($lines)->where('direction', CashMovement::IN)->sum('amount_cents');
        $out = collect($lines)->where('direction', CashMovement::OUT)->sum('amount_cents');

        $this->assertSame(
            $day['balance_cents'],
            $day['opening_cents'] + $in - $out,
            "[$context] the listed movements do not add up to the balance above them"
        );

        // 3. The rule the whole class rests on. These two are shown and must
        //    never be subtracted; if they ever enter the balance, every close
        //    from then on reports a surplus that is not there.
        $withoutInformational = $day['opening_cents']
            + $day['total_in_cents']
            - $day['total_out_cents'];

        $this->assertSame(
            $withoutInformational,
            $day['balance_cents'],
            "[$context] something that is not cash has moved the drawer"
        );
    }

    /**
     * The whole week, in order. One test rather than four, because the point
     * is the sequence: each night's close has to leave the next morning on
     * the float whatever it found, and that can only be seen with the nights
     * in a row.
     */
    public function test_a_trading_week_reconciles_every_night(): void
    {
        // ── Monday ──────────────────────────────────────────────────────
        // An ordinary day: three bills, a bag of vegetables paid in cash.
        $this->sale(self::MON.' 12:30:00', 4500);
        $this->sale(self::MON.' 18:00:00', 7200);
        $this->sale(self::MON.' 21:15:00', 3300);
        $this->cashExpense(self::MON, 2000, 'خضار');

        $monday = $this->cashbox()->day(self::MON);

        $this->assertSame(self::FLOAT, $monday['opening_cents'], 'the first day opens on the float');
        $this->assertSame(15000, $monday['collected_cents']);
        $this->assertSame(2000, $monday['expenses_cents']);
        $this->assertSame(self::FLOAT + 13000, $monday['balance_cents']);
        $this->assertSame(3, $monday['orders_count']);
        $this->assertDayHolds(self::MON, 'Monday, before closing');

        // Counted exactly. The owner takes the 130.00 and leaves the float.
        app(DayCloser::class)->close(self::MON, self::FLOAT + 13000, null, $this->owner);

        $this->assertTrue($this->cashbox()->isClosed(self::MON));
        $this->assertSame(0, $this->cashbox()->closingFor(self::MON)->difference_cents);

        // ── Tuesday ─────────────────────────────────────────────────────
        // Opens on the float, not on Monday's count. A waiter takes an
        // advance out of the drawer, and is docked for a day off — one of
        // those is cash and one is not, which is the distinction the whole
        // class exists to keep.
        $this->assertSame(self::FLOAT, $this->cashbox()->openingCents(self::TUE));

        $this->sale(self::TUE.' 13:00:00', 6000);
        $this->sale(self::TUE.' 20:00:00', 5000, discountCents: 500);

        $this->cashExpense(self::TUE, 8000, 'سلفة', userId: $this->waiter->id);

        app(AbsenceRecorder::class)->create([
            'user_id' => $this->waiter->id,
            'absence_category_id' => AbsenceCategory::firstOrCreate(
                ['name' => 'غياب بدون عذر'], ['affects_pay' => true]
            )->id,
            'deduction_cents' => 4000,
            'absent_on' => self::TUE,
        ], recordedBy: $this->owner->id);

        $tuesday = $this->cashbox()->day(self::TUE);

        $this->assertSame(self::FLOAT, $tuesday['opening_cents']);
        $this->assertSame(11000, $tuesday['collected_cents']);
        $this->assertSame(8000, $tuesday['expenses_cents'], 'the advance left the drawer');
        $this->assertSame(self::FLOAT + 3000, $tuesday['balance_cents']);

        // Shown, never subtracted.
        $this->assertSame(4000, $tuesday['staff_deductions_cents']);
        $this->assertSame(500, $tuesday['bill_discounts_cents']);
        $this->assertDayHolds(self::TUE, 'Tuesday, before closing');

        // The waiter owes the advance and the deduction; neither is a second
        // hit on the drawer.
        $this->assertSame(12000, $this->waiter->fresh()->staffBalanceCents());

        // Counted 20 short — somebody gave the wrong change.
        app(DayCloser::class)->close(self::TUE, self::FLOAT + 2800, 'ناقص 2 شيكل', $this->owner);

        $this->assertSame(-200, $this->cashbox()->closingFor(self::TUE)->difference_cents);
        $this->assertSame('short', $this->cashbox()->closingFor(self::TUE)->differenceState());

        // ── Wednesday ───────────────────────────────────────────────────
        // The shortage is not buried in Wednesday's float: it stays on
        // Tuesday's closing, which names it, and the drawer is made up to
        // the float again for the morning.
        $this->assertSame(self::FLOAT, $this->cashbox()->openingCents(self::WED));

        $this->sale(self::WED.' 14:00:00', 9000);
        $this->movement(self::WED, CashMovement::OUT, CashMovement::TYPE_REFUND, 1500, 'إرجاع طلب');
        $this->movement(self::WED, CashMovement::IN, CashMovement::TYPE_FLOAT, 5000, 'تعزيز الصندوق');
        $this->movement(self::WED, CashMovement::OUT, CashMovement::TYPE_WITHDRAWAL, 20000, 'إيداع في الخزنة');

        $wednesday = $this->cashbox()->day(self::WED);

        $this->assertSame(9000, $wednesday['collected_cents']);
        $this->assertSame(5000, $wednesday['manual_in_cents']);
        $this->assertSame(21500, $wednesday['manual_out_cents'], 'the refund and the withdrawal');
        $this->assertSame(0, $wednesday['expenses_cents']);
        $this->assertSame(self::FLOAT - 7500, $wednesday['balance_cents']);
        $this->assertDayHolds(self::WED, 'Wednesday, before closing');

        // Counted 3 over.
        app(DayCloser::class)->close(self::WED, self::FLOAT - 7200, 'زيادة 3', $this->owner);

        $this->assertSame(300, $this->cashbox()->closingFor(self::WED)->difference_cents);
        $this->assertSame('over', $this->cashbox()->closingFor(self::WED)->differenceState());

        // ── Thursday ────────────────────────────────────────────────────
        $this->assertSame(self::FLOAT, $this->cashbox()->openingCents(self::THU), 'nor is the surplus carried');

        $this->sale(self::THU.' 19:00:00', 4000);
        $thursdayExpense = $this->cashExpense(self::THU, 3000, 'قهوة');

        $this->assertSame(self::FLOAT + 1000, $this->cashbox()->day(self::THU)['balance_cents']);
        $this->assertDayHolds(self::THU, 'Thursday, before the correction');

        // The invoice was 15, not 30 — corrected after the fact. All three
        // writes behind an expense have to move together.
        app(ExpenseRecorder::class)->update($thursdayExpense, ['amount_cents' => 1500]);

        $this->assertSame(self::FLOAT + 2500, $this->cashbox()->day(self::THU)['balance_cents']);
        $this->assertSame(
            1,
            CashMovement::query()->whereDate('occurred_on', self::THU)->count(),
            'the correction moved the movement rather than adding a second one'
        );
        $this->assertDayHolds(self::THU, 'Thursday, after the correction');

        // It turned out to be a bank transfer, not cash at all.
        app(ExpenseRecorder::class)->update($thursdayExpense, ['paid_from_cash' => false]);

        $this->assertSame(self::FLOAT + 4000, $this->cashbox()->day(self::THU)['balance_cents']);
        $this->assertSame(
            0,
            CashMovement::query()->whereDate('occurred_on', self::THU)->count(),
            'the drawer gave the money back'
        );
        $this->assertDayHolds(self::THU, 'Thursday, after moving it off cash');
    }

    /**
     * Reopening Tuesday, correcting it, and closing it again — and what that
     * does to the day after it.
     *
     * Separate from the week above because it is a different question: not
     * "does the arithmetic hold", but "what happens to the rest of the week
     * when somebody edits the middle of it". A manager does this the morning
     * after finding a mistake, and the answer has to be predictable — and it
     * is: the correction lands on Tuesday, and Wednesday still opened on the
     * float it was left with.
     */
    public function test_correcting_a_night_after_the_fact_stays_on_that_night(): void
    {
        $this->sale(self::MON.' 12:00:00', 10000);
        app(DayCloser::class)->close(self::MON, self::FLOAT + 10000, null, $this->owner);

        $this->sale(self::TUE.' 12:00:00', 5000);
        app(DayCloser::class)->close(self::TUE, self::FLOAT + 5000, null, $this->owner);

        $this->assertSame(self::FLOAT, $this->cashbox()->openingCents(self::WED));

        app(DayCloser::class)->reopen(self::TUE, $this->owner);

        $this->assertFalse($this->cashbox()->isClosed(self::TUE));
        $this->assertSame(self::FLOAT, $this->cashbox()->openingCents(self::WED));

        // A bill that had been missed is added to Tuesday.
        $this->sale(self::TUE.' 22:00:00', 2000);

        $tuesday = $this->cashbox()->day(self::TUE);
        $this->assertSame(7000, $tuesday['collected_cents'], 'the late bill is on Tuesday');
        $this->assertSame(self::FLOAT + 7000, $tuesday['balance_cents']);
        $this->assertSame(self::FLOAT, $this->cashbox()->openingCents(self::WED), 'and only on Tuesday');
        $this->assertDayHolds(self::TUE, 'Tuesday, reopened and corrected');

        // Closed again on the real count. The figures are recomputed, not
        // the ones stored the first time.
        app(DayCloser::class)->close(self::TUE, self::FLOAT + 7000, 'أُضيفت فاتورة كانت ناقصة', $this->owner);

        $closing = $this->cashbox()->closingFor(self::TUE);

        $this->assertTrue($closing->isClosed(), 'the reopen stamp is cleared by closing again');
        $this->assertNull($closing->reopened_at);
        $this->assertSame(self::FLOAT + 7000, $closing->expected_cents);
        $this->assertSame(0, $closing->difference_cents);
        $this->assertSame(self::FLOAT, $this->cashbox()->openingCents(self::WED));
    }

    /**
     * Deleting an expense a week later gives the drawer its money back — on
     * the day the expense belonged to, not today.
     */
    public function test_removing_an_expense_returns_it_to_its_own_day(): void
    {
        $this->sale(self::MON.' 12:00:00', 10000);
        $expense = $this->cashExpense(self::MON, 2500, 'خضار');

        $this->assertSame(self::FLOAT + 7500, $this->cashbox()->day(self::MON)['balance_cents']);

        app(ExpenseRecorder::class)->delete($expense);

        $this->assertSame(self::FLOAT + 10000, $this->cashbox()->day(self::MON)['balance_cents']);
        $this->assertSame(0, CashMovement::query()->count(), 'and takes its movement with it');
        $this->assertDayHolds(self::MON, 'Monday, after the expense was removed');
    }

    /**
     * An advance deleted clears the employee's account as well as the
     * drawer — the two writes that must never drift apart.
     */
    public function test_removing_an_advance_clears_both_the_drawer_and_the_employee(): void
    {
        $this->sale(self::MON.' 12:00:00', 50000);
        $advance = $this->cashExpense(self::MON, 20000, 'سلفة', userId: $this->waiter->id);

        $this->assertSame(self::FLOAT + 30000, $this->cashbox()->day(self::MON)['balance_cents']);
        $this->assertSame(20000, $this->waiter->fresh()->staffBalanceCents());

        app(ExpenseRecorder::class)->delete($advance);

        $this->assertSame(self::FLOAT + 50000, $this->cashbox()->day(self::MON)['balance_cents']);
        $this->assertSame(0, $this->waiter->fresh()->staffBalanceCents());
        $this->assertDayHolds(self::MON, 'Monday, after the advance was removed');
    }
}
