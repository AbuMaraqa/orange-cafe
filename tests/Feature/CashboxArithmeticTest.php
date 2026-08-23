<?php

namespace Tests\Feature;

use App\Models\Absence;
use App\Models\AbsenceCategory;
use App\Models\CashMovement;
use App\Models\DailyClosing;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Order;
use App\Models\StaffLedgerEntry;
use App\Models\User;
use App\Services\Accounting\AbsenceRecorder;
use App\Services\Accounting\Cashbox;
use App\Services\Accounting\DayCloser;
use App\Services\Accounting\ExpenseRecorder;
use App\Services\Accounting\StaffLedger;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * The money. Every figure the cafe will trust is asserted here, because a
 * wrong number in this subsystem is invisible until the drawer is counted
 * and does not match — by which time nobody can say which of the last
 * hundred entries caused it.
 *
 * The rule under test throughout: the drawer moves only when physical cash
 * moves.
 */
class CashboxArithmeticTest extends TestCase
{
    use RefreshDatabase;

    private string $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(LanguageSeeder::class);
        $this->seed(PermissionSeeder::class);

        $this->today = now()->toDateString();
    }

    private function user(string $name = 'أحمد', string $role = 'waiter'): User
    {
        $user = User::create([
            'name' => $name,
            'email' => strtolower($name).fake()->unique()->numberBetween(1, 99999).'@test.local',
            'password' => 'password',
            'locale' => 'ar',
            'email_verified_at' => now(),
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function expenseCategory(string $name = 'مشتريات'): ExpenseCategory
    {
        return ExpenseCategory::firstOrCreate(['name' => $name]);
    }

    private function absenceCategory(string $name = 'غياب بدون عذر'): AbsenceCategory
    {
        return AbsenceCategory::firstOrCreate(['name' => $name], ['affects_pay' => true]);
    }

    /** A paid order, which is the only way money is "collected". */
    private function paidOrder(int $totalCents, ?int $collectedCents = null, int $discountCents = 0): Order
    {
        return Order::create([
            'reference' => 'R-'.fake()->unique()->numberBetween(1, 999999),
            'status' => 'served',
            'total_cents' => $totalCents,
            'discount_cents' => $discountCents,
            'amount_paid_cents' => $collectedCents,
            'paid_at' => now(),
        ]);
    }

    private function cashbox(): Cashbox
    {
        return app(Cashbox::class);
    }

    // ---------------------------------------------------------------
    // Collection
    // ---------------------------------------------------------------

    public function test_collection_is_what_the_till_received_not_what_was_billed(): void
    {
        // A cashier rounding 202 down to 200 means the drawer holds 200.
        $this->paidOrder(totalCents: 20200, collectedCents: 20000);

        $this->assertSame(20000, $this->cashbox()->collectedCents($this->today));
    }

    public function test_an_unpaid_order_is_not_collected(): void
    {
        Order::create([
            'reference' => 'R-unpaid',
            'status' => 'served',
            'total_cents' => 5000,
        ]);

        $this->assertSame(0, $this->cashbox()->collectedCents($this->today));
    }

    // ---------------------------------------------------------------
    // Expenses
    // ---------------------------------------------------------------

    public function test_a_cash_expense_leaves_the_drawer(): void
    {
        $this->paidOrder(10000);

        app(ExpenseRecorder::class)->create([
            'expense_category_id' => $this->expenseCategory()->id,
            'amount_cents' => 2500,
            'spent_on' => $this->today,
            'reason' => 'خضار',
            'paid_from_cash' => true,
        ], recordedBy: null);

        $day = $this->cashbox()->day($this->today);

        $this->assertSame(10000, $day['collected_cents']);
        $this->assertSame(2500, $day['expenses_cents']);
        $this->assertSame(7500, $day['balance_cents']);
    }

    /**
     * Rent paid by bank transfer is an expense of the business and no
     * movement of the till. Counting it against the drawer would leave the
     * nightly close short by the rent.
     */
    public function test_a_non_cash_expense_does_not_touch_the_drawer(): void
    {
        $this->paidOrder(10000);

        app(ExpenseRecorder::class)->create([
            'expense_category_id' => $this->expenseCategory('إيجار')->id,
            'amount_cents' => 50000,
            'spent_on' => $this->today,
            'reason' => 'إيجار الشهر',
            'paid_from_cash' => false,
        ], recordedBy: null);

        $day = $this->cashbox()->day($this->today);

        $this->assertSame(0, $day['expenses_cents']);
        $this->assertSame(10000, $day['balance_cents']);
    }

    /**
     * A cash expense writes both an Expense and a CashMovement — one for
     * category reporting, one for the drawer. If the cashbox summed both,
     * the money would leave twice.
     */
    public function test_a_cash_expense_is_not_counted_twice(): void
    {
        $this->paidOrder(10000);

        app(ExpenseRecorder::class)->create([
            'expense_category_id' => $this->expenseCategory()->id,
            'amount_cents' => 3000,
            'spent_on' => $this->today,
            'reason' => 'قهوة',
            'paid_from_cash' => true,
        ], recordedBy: null);

        $this->assertSame(1, CashMovement::query()->count());
        $this->assertSame(7000, $this->cashbox()->day($this->today)['balance_cents']);
    }

    public function test_editing_an_expense_moves_the_drawer_with_it(): void
    {
        $this->paidOrder(10000);

        $expense = app(ExpenseRecorder::class)->create([
            'expense_category_id' => $this->expenseCategory()->id,
            'amount_cents' => 3000,
            'spent_on' => $this->today,
            'reason' => 'قهوة',
            'paid_from_cash' => true,
        ], recordedBy: null);

        app(ExpenseRecorder::class)->update($expense, ['amount_cents' => 1000]);

        $this->assertSame(1, CashMovement::query()->count());
        $this->assertSame(1000, (int) CashMovement::query()->value('amount_cents'));
        $this->assertSame(9000, $this->cashbox()->day($this->today)['balance_cents']);
    }

    /**
     * Switching an expense to a non-cash payment must give the drawer its
     * money back — a stale movement left behind is a till that never
     * balances again.
     */
    public function test_switching_an_expense_off_cash_removes_its_movement(): void
    {
        $this->paidOrder(10000);

        $expense = app(ExpenseRecorder::class)->create([
            'expense_category_id' => $this->expenseCategory()->id,
            'amount_cents' => 3000,
            'spent_on' => $this->today,
            'reason' => 'قهوة',
            'paid_from_cash' => true,
        ], recordedBy: null);

        app(ExpenseRecorder::class)->update($expense, ['paid_from_cash' => false]);

        $this->assertSame(0, CashMovement::query()->count());
        $this->assertSame(10000, $this->cashbox()->day($this->today)['balance_cents']);
    }

    public function test_deleting_an_expense_returns_the_money_to_the_drawer(): void
    {
        $this->paidOrder(10000);

        $expense = app(ExpenseRecorder::class)->create([
            'expense_category_id' => $this->expenseCategory()->id,
            'amount_cents' => 3000,
            'spent_on' => $this->today,
            'reason' => 'قهوة',
            'paid_from_cash' => true,
        ], recordedBy: null);

        app(ExpenseRecorder::class)->delete($expense);

        $this->assertSame(0, CashMovement::query()->count());
        $this->assertSame(0, StaffLedgerEntry::query()->count());
        $this->assertSame(10000, $this->cashbox()->day($this->today)['balance_cents']);
    }

    // ---------------------------------------------------------------
    // Staff advances: one act, two effects
    // ---------------------------------------------------------------

    public function test_an_advance_leaves_the_drawer_and_lands_on_the_employee(): void
    {
        $ahmad = $this->user();
        $this->paidOrder(50000);

        app(ExpenseRecorder::class)->create([
            'expense_category_id' => $this->expenseCategory('سلفة')->id,
            'user_id' => $ahmad->id,
            'amount_cents' => 20000,
            'spent_on' => $this->today,
            'reason' => 'سلفة شخصية',
            'paid_from_cash' => true,
        ], recordedBy: null);

        $this->assertSame(30000, $this->cashbox()->day($this->today)['balance_cents']);
        $this->assertSame(20000, $ahmad->staffBalanceCents());
    }

    public function test_detaching_an_expense_from_an_employee_clears_their_line(): void
    {
        $ahmad = $this->user();

        $expense = app(ExpenseRecorder::class)->create([
            'expense_category_id' => $this->expenseCategory('سلفة')->id,
            'user_id' => $ahmad->id,
            'amount_cents' => 20000,
            'spent_on' => $this->today,
            'reason' => 'سلفة',
            'paid_from_cash' => true,
        ], recordedBy: null);

        $this->assertSame(20000, $ahmad->staffBalanceCents());

        app(ExpenseRecorder::class)->update($expense, ['user_id' => null]);

        $this->assertSame(0, $ahmad->fresh()->staffBalanceCents());
    }

    // ---------------------------------------------------------------
    // Absences: the deduction that must NOT touch the drawer
    // ---------------------------------------------------------------

    public function test_an_absence_deduction_charges_the_employee_only(): void
    {
        $ahmad = $this->user();
        $this->paidOrder(10000);

        app(AbsenceRecorder::class)->create([
            'user_id' => $ahmad->id,
            'absence_category_id' => $this->absenceCategory()->id,
            'deduction_cents' => 5000,
            'absent_on' => $this->today,
            'reason' => 'غياب بدون إبلاغ',
        ], recordedBy: null);

        $day = $this->cashbox()->day($this->today);

        // Shown...
        $this->assertSame(5000, $day['staff_deductions_cents']);
        // ...but the drawer is untouched, because no cash moved.
        $this->assertSame(10000, $day['balance_cents']);
        // And the employee owes it.
        $this->assertSame(5000, $ahmad->staffBalanceCents());
    }

    /**
     * The rule the cafe asked for by name: editing a deduction updates the
     * one line rather than adding a second.
     */
    public function test_editing_a_deduction_updates_its_line_instead_of_adding_one(): void
    {
        $ahmad = $this->user();

        $absence = app(AbsenceRecorder::class)->create([
            'user_id' => $ahmad->id,
            'absence_category_id' => $this->absenceCategory()->id,
            'deduction_cents' => 5000,
            'absent_on' => $this->today,
        ], recordedBy: null);

        app(AbsenceRecorder::class)->update($absence, ['deduction_cents' => 3000]);

        $this->assertSame(1, StaffLedgerEntry::query()->count());
        $this->assertSame(3000, $ahmad->fresh()->staffBalanceCents());
    }

    public function test_a_deduction_reduced_to_zero_removes_the_line_but_keeps_the_absence(): void
    {
        $ahmad = $this->user();

        $absence = app(AbsenceRecorder::class)->create([
            'user_id' => $ahmad->id,
            'absence_category_id' => $this->absenceCategory()->id,
            'deduction_cents' => 5000,
            'absent_on' => $this->today,
        ], recordedBy: null);

        app(AbsenceRecorder::class)->update($absence, ['deduction_cents' => 0]);

        $this->assertSame(0, StaffLedgerEntry::query()->count());
        $this->assertSame(0, $ahmad->fresh()->staffBalanceCents());
        $this->assertDatabaseHas('absences', ['id' => $absence->id]);
    }

    public function test_an_absence_with_no_deduction_creates_no_line(): void
    {
        $ahmad = $this->user();

        app(AbsenceRecorder::class)->create([
            'user_id' => $ahmad->id,
            'absence_category_id' => $this->absenceCategory('إجازة مرضية')->id,
            'deduction_cents' => 0,
            'absent_on' => $this->today,
        ], recordedBy: null);

        $this->assertSame(0, StaffLedgerEntry::query()->count());
        $this->assertSame(1, Absence::query()->count());
    }

    public function test_deleting_an_absence_removes_its_deduction(): void
    {
        $ahmad = $this->user();

        $absence = app(AbsenceRecorder::class)->create([
            'user_id' => $ahmad->id,
            'absence_category_id' => $this->absenceCategory()->id,
            'deduction_cents' => 5000,
            'absent_on' => $this->today,
        ], recordedBy: null);

        app(AbsenceRecorder::class)->delete($absence);

        $this->assertSame(0, StaffLedgerEntry::query()->count());
        $this->assertSame(0, $ahmad->fresh()->staffBalanceCents());
    }

    // ---------------------------------------------------------------
    // The employee statement
    // ---------------------------------------------------------------

    public function test_the_statement_runs_a_balance_down_the_page(): void
    {
        $ahmad = $this->user();
        $ledger = app(StaffLedger::class);

        $ledger->post($ahmad, StaffLedgerEntry::TYPE_ADVANCE, 20000, '2026-08-20', 'سلفة');
        $ledger->post($ahmad, StaffLedgerEntry::TYPE_ABSENCE_DEDUCTION, 5000, '2026-08-21', 'غياب بدون عذر');
        $ledger->post($ahmad, StaffLedgerEntry::TYPE_PAYMENT, 10000, '2026-08-25', 'دفعة');

        $rows = $ledger->statement($ahmad->fresh());

        $this->assertSame([20000, 25000, 15000], array_column($rows, 'balance_cents'));

        // Debit and credit are derived from the sign, never stored, so they
        // cannot contradict the balance.
        $this->assertSame([20000, 5000, 0], array_column($rows, 'debit_cents'));
        $this->assertSame([0, 0, 10000], array_column($rows, 'credit_cents'));
    }

    public function test_the_staff_summary_splits_the_balance_by_kind(): void
    {
        $ahmad = $this->user();
        $ledger = app(StaffLedger::class);

        $ledger->post($ahmad, StaffLedgerEntry::TYPE_ADVANCE, 30000, $this->today, 'سلفة');
        $ledger->post($ahmad, StaffLedgerEntry::TYPE_ABSENCE_DEDUCTION, 5000, $this->today, 'غياب');
        $ledger->post($ahmad, StaffLedgerEntry::TYPE_PAYMENT, 10000, $this->today, 'دفعة');

        $summary = $ledger->summary($ahmad->fresh());

        $this->assertSame(30000, $summary['advances_cents']);
        $this->assertSame(5000, $summary['absence_deductions_cents']);
        $this->assertSame(10000, $summary['payments_cents']);
        $this->assertSame(25000, $summary['balance_cents']);
    }

    // ---------------------------------------------------------------
    // Closing the day
    // ---------------------------------------------------------------

    public function test_closing_records_the_expected_the_counted_and_the_gap(): void
    {
        $admin = $this->user('مدير', 'admin');
        $this->paidOrder(100000);

        app(ExpenseRecorder::class)->create([
            'expense_category_id' => $this->expenseCategory()->id,
            'amount_cents' => 20000,
            'spent_on' => $this->today,
            'reason' => 'مشتريات',
            'paid_from_cash' => true,
        ], recordedBy: $admin->id);

        // Expected = 0 opening + 100000 in − 20000 out = 80000.
        $closing = app(DayCloser::class)->close($this->today, countedCents: 79000, notes: 'نقص بسيط', by: $admin);

        $this->assertSame(0, $closing->opening_cents);
        $this->assertSame(100000, $closing->collected_cents);
        $this->assertSame(20000, $closing->expenses_cents);
        $this->assertSame(80000, $closing->expected_cents);
        $this->assertSame(79000, $closing->counted_cents);
        $this->assertSame(-1000, $closing->difference_cents);
        $this->assertSame('short', $closing->differenceState());
        $this->assertSame($admin->id, $closing->closed_by);
    }

    public function test_a_surplus_is_reported_as_such(): void
    {
        $admin = $this->user('مدير', 'admin');
        $this->paidOrder(50000);

        $closing = app(DayCloser::class)->close($this->today, 50500, null, $admin);

        $this->assertSame(500, $closing->difference_cents);
        $this->assertSame('over', $closing->differenceState());
    }

    public function test_a_matching_count_is_reported_as_balanced(): void
    {
        $admin = $this->user('مدير', 'admin');
        $this->paidOrder(50000);

        $closing = app(DayCloser::class)->close($this->today, 50000, null, $admin);

        $this->assertSame(0, $closing->difference_cents);
        $this->assertSame('balanced', $closing->differenceState());
    }

    /**
     * The counted figure carries forward, not the expected one. Carrying
     * the expected figure would bury a shortfall and every following day
     * would inherit it.
     */
    public function test_tomorrow_opens_on_what_was_actually_counted(): void
    {
        $admin = $this->user('مدير', 'admin');
        $yesterday = now()->subDay()->toDateString();

        // created_at is not fillable, so it has to be forced after the fact
        // — passing it to create() silently leaves the order on today and
        // this test would then be asserting nothing.
        $order = $this->paidOrder(100000);
        $order->forceFill([
            'paid_at' => now()->subDay(),
            'created_at' => now()->subDay(),
        ])->save();

        app(DayCloser::class)->close($yesterday, countedCents: 95000, notes: null, by: $admin);

        $this->assertSame(95000, $this->cashbox()->openingCents($this->today));
        $this->assertSame(95000, $this->cashbox()->day($this->today)['balance_cents']);
    }

    public function test_a_day_cannot_be_closed_twice(): void
    {
        $admin = $this->user('مدير', 'admin');
        app(DayCloser::class)->close($this->today, 0, null, $admin);

        $this->expectException(RuntimeException::class);
        app(DayCloser::class)->close($this->today, 0, null, $admin);
    }

    public function test_reopening_is_stamped_and_lets_the_day_be_closed_again(): void
    {
        $admin = $this->user('مدير', 'admin');
        $this->paidOrder(50000);

        app(DayCloser::class)->close($this->today, 50000, null, $admin);
        $this->assertTrue($this->cashbox()->isClosed($this->today));

        $reopened = app(DayCloser::class)->reopen($this->today, $admin);

        $this->assertSame($admin->id, $reopened->reopened_by);
        $this->assertNotNull($reopened->reopened_at);
        $this->assertFalse($this->cashbox()->isClosed($this->today));

        // Closing again reuses the same row rather than creating a second.
        app(DayCloser::class)->close($this->today, 49000, 'إعادة عد', $admin);

        $this->assertSame(1, DailyClosing::query()->count());
        $this->assertSame(-1000, DailyClosing::query()->value('difference_cents'));
    }

    // ---------------------------------------------------------------
    // The whole picture
    // ---------------------------------------------------------------

    public function test_the_day_reconciles_across_every_kind_of_movement(): void
    {
        $admin = $this->user('مدير', 'admin');
        $ahmad = $this->user('أحمد');
        $yesterday = now()->subDay()->toDateString();

        // Yesterday left 100,00 in the drawer.
        app(DayCloser::class)->close($yesterday, countedCents: 100000, notes: null, by: $admin);

        // Today: 600,00 taken, a bill discounted by 50,00 along the way.
        $this->paidOrder(totalCents: 60000, collectedCents: 60000, discountCents: 5000);

        // 150,00 of shopping, paid in cash.
        app(ExpenseRecorder::class)->create([
            'expense_category_id' => $this->expenseCategory()->id,
            'amount_cents' => 15000,
            'spent_on' => $this->today,
            'reason' => 'مشتريات',
            'paid_from_cash' => true,
        ], recordedBy: $admin->id);

        // 200,00 advanced to أحمد out of the drawer.
        app(ExpenseRecorder::class)->create([
            'expense_category_id' => $this->expenseCategory('سلفة')->id,
            'user_id' => $ahmad->id,
            'amount_cents' => 20000,
            'spent_on' => $this->today,
            'reason' => 'سلفة',
            'paid_from_cash' => true,
        ], recordedBy: $admin->id);

        // 50,00 deducted from أحمد for an absence — no cash involved.
        app(AbsenceRecorder::class)->create([
            'user_id' => $ahmad->id,
            'absence_category_id' => $this->absenceCategory()->id,
            'deduction_cents' => 5000,
            'absent_on' => $this->today,
        ], recordedBy: $admin->id);

        // 300,00 taken to the bank.
        CashMovement::create([
            'direction' => CashMovement::OUT,
            'type' => CashMovement::TYPE_WITHDRAWAL,
            'amount_cents' => 30000,
            'occurred_on' => $this->today,
            'description' => 'إيداع بنكي',
            'recorded_by' => $admin->id,
        ]);

        $day = $this->cashbox()->day($this->today);

        $this->assertSame(100000, $day['opening_cents']);
        $this->assertSame(60000, $day['total_in_cents']);
        $this->assertSame(35000, $day['expenses_cents']);      // 15000 + 20000
        $this->assertSame(30000, $day['manual_out_cents']);    // the bank run
        $this->assertSame(65000, $day['total_out_cents']);

        // 100000 + 60000 − 35000 − 30000
        $this->assertSame(95000, $day['balance_cents']);
        $this->assertSame(-5000, $day['net_movement_cents']);

        // Shown, and correctly excluded from the drawer.
        $this->assertSame(5000, $day['staff_deductions_cents']);
        $this->assertSame(5000, $day['bill_discounts_cents']);

        // أحمد owes the advance plus the deduction.
        $this->assertSame(25000, $ahmad->fresh()->staffBalanceCents());
    }
}
