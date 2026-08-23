<?php

namespace Tests\Feature;

use App\Models\Absence;
use App\Models\AbsenceCategory;
use App\Models\DailyClosing;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Order;
use App\Models\StaffLedgerEntry;
use App\Models\User;
use Database\Seeders\AccountingCategorySeeder;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The screens, and who is allowed on them.
 *
 * The permission that matters most is `cashbox.reopen`: a closed day is a
 * signed statement, and a cashier being able to quietly reopen and rewrite
 * one would make every close meaningless. It is asserted from both sides —
 * that an admin can, and that a cashier cannot.
 */
class AccountingAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(LanguageSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(AccountingCategorySeeder::class);
    }

    private function user(string $role, string $name = 'مستخدم'): User
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

    private function category(): ExpenseCategory
    {
        return ExpenseCategory::query()->firstOrFail();
    }

    // ---------------------------------------------------------------
    // Screens render
    // ---------------------------------------------------------------

    public function test_the_cashbox_screen_renders_for_an_admin(): void
    {
        $this->actingAs($this->user('admin'))
            ->get(route('admin.accounting.cashbox'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Accounting/Cashbox/Index')
                ->has('summary.balance_cents')
                ->where('can.reopen', true));
    }

    public function test_every_accounting_screen_renders(): void
    {
        $admin = $this->user('admin');

        foreach ([
            'admin.accounting.expenses.index',
            'admin.accounting.absences.index',
            'admin.accounting.expense-categories.index',
            'admin.accounting.absence-categories.index',
            'admin.accounting.staff.index',
            'admin.accounting.reports',
            'admin.accounting.cashbox.closings',
        ] as $name) {
            $this->actingAs($admin)->get(route($name))->assertOk();
        }
    }

    public function test_a_staff_statement_renders(): void
    {
        $admin = $this->user('admin');
        $ahmad = $this->user('waiter', 'أحمد');

        $this->actingAs($admin)
            ->get(route('admin.accounting.staff.show', $ahmad))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Accounting/Staff/Show')
                ->where('staffMember.name', 'أحمد')
                ->has('summary.balance_cents'));
    }

    // ---------------------------------------------------------------
    // Writing through the HTTP layer
    // ---------------------------------------------------------------

    /**
     * The forms send currency units. Turning them into cents in the browser
     * would put a float multiplication between the user and the database.
     */
    public function test_an_expense_form_amount_is_converted_to_cents(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)
            ->post(route('admin.accounting.expenses.store'), [
                'expense_category_id' => $this->category()->id,
                'amount' => '12.10',
                'reason' => 'خضار',
                'paid_from_cash' => true,
            ])
            ->assertRedirect();

        // 12.10 * 100 is 1209.999... in binary floating point; a cast would
        // store 1209 and lose a piastre.
        $this->assertSame(1210, (int) Expense::query()->value('amount_cents'));
    }

    public function test_an_expense_defaults_to_today(): void
    {
        $this->actingAs($this->user('admin'))
            ->post(route('admin.accounting.expenses.store'), [
                'expense_category_id' => $this->category()->id,
                'amount' => '5',
                'reason' => 'قهوة',
            ])
            ->assertRedirect();

        $this->assertSame(now()->toDateString(), Expense::query()->first()->spent_on->toDateString());
    }

    public function test_an_absence_with_a_deduction_reaches_the_staff_account(): void
    {
        $admin = $this->user('admin');
        $ahmad = $this->user('waiter', 'أحمد');

        $this->actingAs($admin)
            ->post(route('admin.accounting.absences.store'), [
                'user_id' => $ahmad->id,
                'absence_category_id' => AbsenceCategory::query()->firstOrFail()->id,
                'deduction' => '50',
                'reason' => 'غياب بدون إبلاغ',
            ])
            ->assertRedirect();

        $this->assertSame(5000, $ahmad->fresh()->staffBalanceCents());
        $this->assertSame(
            StaffLedgerEntry::TYPE_ABSENCE_DEDUCTION,
            StaffLedgerEntry::query()->value('type')
        );
    }

    public function test_a_collection_moves_the_drawer(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)
            ->post(route('admin.accounting.cashbox.movements.store'), [
                'direction' => 'in',
                'type' => 'collection',
                'amount' => '250',
                'description' => 'تحصيل نقدي',
            ])
            ->assertRedirect();

        $this->actingAs($admin)
            ->get(route('admin.accounting.cashbox'))
            ->assertInertia(fn ($page) => $page->where('summary.balance_cents', 25000));
    }

    // ---------------------------------------------------------------
    // A closed day is closed
    // ---------------------------------------------------------------

    public function test_closing_stores_the_gap_and_names_who_signed_it(): void
    {
        $admin = $this->user('admin');

        Order::create([
            'reference' => 'R-1',
            'status' => 'served',
            'total_cents' => 40000,
            'paid_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.accounting.cashbox.close'), [
                'date' => now()->toDateString(),
                'counted' => '395',
                'notes' => 'نقص 5',
            ])
            ->assertRedirect();

        $closing = DailyClosing::query()->firstOrFail();

        $this->assertSame(40000, $closing->expected_cents);
        $this->assertSame(39500, $closing->counted_cents);
        $this->assertSame(-500, $closing->difference_cents);
        $this->assertSame($admin->id, $closing->closed_by);
    }

    public function test_an_expense_cannot_be_added_to_a_closed_day(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)->post(route('admin.accounting.cashbox.close'), [
            'date' => now()->toDateString(),
            'counted' => '0',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.accounting.expenses.store'), [
                'expense_category_id' => $this->category()->id,
                'amount' => '10',
                'reason' => 'بعد الإغلاق',
            ])
            ->assertSessionHasErrors('spent_on');

        $this->assertSame(0, Expense::query()->count());
    }

    /**
     * An absence deducts from an employee, not from the drawer, so a closed
     * day does not block it — the day's cash figures are unaffected.
     */
    public function test_an_absence_can_still_be_filed_on_a_closed_day(): void
    {
        $admin = $this->user('admin');
        $ahmad = $this->user('waiter', 'أحمد');

        $this->actingAs($admin)->post(route('admin.accounting.cashbox.close'), [
            'date' => now()->toDateString(),
            'counted' => '0',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.accounting.absences.store'), [
                'user_id' => $ahmad->id,
                'absence_category_id' => AbsenceCategory::query()->firstOrFail()->id,
                'deduction' => '50',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Absence::query()->count());
    }

    // ---------------------------------------------------------------
    // Permissions
    // ---------------------------------------------------------------

    /**
     * The accounting section belongs to the owner, not to the till.
     *
     * A cashier handles money all evening and still has no business seeing
     * what the business earned, what it spent, or what anyone is paid. The
     * two are different jobs that happen to touch the same banknotes, and
     * the app draws the line between them here.
     *
     * Taking payment is not in this list on purpose — see
     * CashierAndWaiterShiftTest, which walks the job a cashier actually does
     * and proves none of it goes through these screens.
     */
    public function test_the_accounting_section_is_closed_to_a_cashier(): void
    {
        $cashier = $this->user('cashier');
        $today = now()->toDateString();

        $forbidden = [
            'GET' => [
                route('admin.accounting.cashbox'),
                route('admin.accounting.expenses.index'),
                route('admin.accounting.reports'),
                route('admin.accounting.staff.index'),
            ],
            'POST' => [
                route('admin.accounting.cashbox.close'),
                route('admin.accounting.cashbox.reopen'),
            ],
        ];

        foreach ($forbidden as $verb => $urls) {
            foreach ($urls as $url) {
                $this->actingAs($cashier)
                    ->call($verb, $url, ['date' => $today, 'counted' => '0'])
                    ->assertForbidden();
            }
        }

        // Nothing was signed off, and nothing was reopened, by somebody who
        // should not have been able to reach either.
        $this->assertSame(0, DailyClosing::query()->count());
    }

    public function test_an_admin_can_reopen_a_day(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)->post(route('admin.accounting.cashbox.close'), [
            'date' => now()->toDateString(),
            'counted' => '0',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.accounting.cashbox.reopen'), ['date' => now()->toDateString()])
            ->assertRedirect();

        $this->assertNotNull(DailyClosing::query()->value('reopened_at'));
    }

    public function test_a_cashier_cannot_see_staff_accounts(): void
    {
        $cashier = $this->user('cashier');

        $this->actingAs($cashier)->get(route('admin.accounting.staff.index'))->assertForbidden();
    }

    public function test_a_waiter_is_kept_out_of_the_money_entirely(): void
    {
        $waiter = $this->user('waiter');

        foreach ([
            'admin.accounting.cashbox',
            'admin.accounting.expenses.index',
            'admin.accounting.absences.index',
            'admin.accounting.staff.index',
            'admin.accounting.reports',
        ] as $name) {
            $this->actingAs($waiter)->get(route($name))->assertForbidden();
        }
    }

    // ---------------------------------------------------------------
    // Categories
    // ---------------------------------------------------------------

    public function test_a_category_in_use_cannot_be_deleted(): void
    {
        $admin = $this->user('admin');
        $category = $this->category();

        Expense::create([
            'expense_category_id' => $category->id,
            'amount_cents' => 1000,
            'spent_on' => now()->toDateString(),
            'reason' => 'شيء',
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.accounting.expense-categories.destroy', $category))
            ->assertSessionHasErrors('name');

        $this->assertDatabaseHas('expense_categories', ['id' => $category->id]);
    }

    public function test_an_unused_category_is_deleted(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)
            ->post(route('admin.accounting.expense-categories.store'), ['name' => 'فئة تجريبية'])
            ->assertRedirect();

        $created = ExpenseCategory::query()->where('name', 'فئة تجريبية')->firstOrFail();

        $this->actingAs($admin)
            ->delete(route('admin.accounting.expense-categories.destroy', $created))
            ->assertRedirect();

        $this->assertDatabaseMissing('expense_categories', ['id' => $created->id]);
    }

    // ---------------------------------------------------------------
    // Reports
    // ---------------------------------------------------------------

    public function test_the_report_export_is_utf8_with_a_bom(): void
    {
        $admin = $this->user('admin');

        Expense::create([
            'expense_category_id' => $this->category()->id,
            'amount_cents' => 1000,
            'spent_on' => now()->toDateString(),
            'reason' => 'مشتريات',
        ]);

        $response = $this->actingAs($admin)->get(route('admin.accounting.reports.export', [
            'tab' => 'expenses',
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->toDateString(),
        ]));

        $response->assertOk();

        $body = $response->streamedContent();

        // Without the BOM, Excel on Windows renders every Arabic name as
        // mojibake and it looks like the app corrupted the data.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringContainsString('مشتريات', $body);
    }
}
