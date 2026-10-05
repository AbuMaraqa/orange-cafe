<?php

namespace Tests\Feature;

use App\Models\Absence;
use App\Models\AbsenceCategory;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\StaffLedgerEntry;
use App\Models\User;
use Database\Seeders\AccountingCategorySeeder;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * "اذا حذفت مستخدم ما بدي ينحذف اشي معاه."
 *
 * Before soft deletes, removing a waiter cascaded through their staff
 * ledger, blanked "who took this order" and "who received this payment",
 * and refused outright if they had an absence on file.
 */
class DeletingAUserKeepsTheirHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $waiter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(LanguageSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(AccountingCategorySeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->waiter = User::factory()->create(['name' => 'محمد مرقة', 'username' => 'محمد مرقة']);
        $this->waiter->assignRole('waiter');
    }

    /** A shift's worth of everything that points at the waiter. */
    private function giveTheWaiterAHistory(): Order
    {
        $order = Order::create([
            'status' => 'served',
            'total_cents' => 4000,
            'amount_paid_cents' => 4000,
            'created_by' => $this->waiter->id,
            'paid_by' => $this->waiter->id,
            'paid_at' => now(),
        ]);

        OrderPayment::create([
            'order_id' => $order->id,
            'amount_cents' => 4000,
            'method' => 'cash',
            'received_at' => now(),
            'received_by' => $this->waiter->id,
        ]);

        StaffLedgerEntry::create([
            'user_id' => $this->waiter->id,
            'type' => StaffLedgerEntry::TYPE_ADVANCE,
            'amount_cents' => 5000,
            'occurred_on' => now()->toDateString(),
            'description' => 'سلفة',
            'recorded_by' => $this->waiter->id,
        ]);

        Absence::create([
            'user_id' => $this->waiter->id,
            'absence_category_id' => AbsenceCategory::query()->firstOrFail()->id,
            'absent_on' => now()->toDateString(),
        ]);

        Expense::create([
            'expense_category_id' => ExpenseCategory::query()->firstOrFail()->id,
            'user_id' => $this->waiter->id,
            'amount_cents' => 1500,
            'spent_on' => now()->toDateString(),
            'reason' => 'وجبة',
        ]);

        return $order;
    }

    public function test_deleting_a_user_leaves_every_record_that_points_at_them(): void
    {
        $order = $this->giveTheWaiterAHistory();

        $this->actingAs($this->admin)
            ->delete(route('admin.users.destroy', $this->waiter))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.users.index'));

        $this->assertSoftDeleted($this->waiter);

        $order->refresh();
        $this->assertSame($this->waiter->id, $order->created_by);
        $this->assertSame($this->waiter->id, $order->paid_by);
        $this->assertSame('محمد مرقة', $order->createdBy->name);
        $this->assertSame('محمد مرقة', $order->paidBy->name);
        $this->assertSame('محمد مرقة', OrderPayment::firstOrFail()->receivedBy->name);

        $this->assertSame(1, StaffLedgerEntry::where('user_id', $this->waiter->id)->count());
        $this->assertSame('محمد مرقة', StaffLedgerEntry::firstOrFail()->recordedBy->name);
        $this->assertSame(1, Absence::where('user_id', $this->waiter->id)->count());
        $this->assertSame(1, Expense::where('user_id', $this->waiter->id)->count());
        $this->assertSame('محمد مرقة', Expense::firstOrFail()->user->name);
    }

    public function test_a_deleted_user_can_no_longer_log_in_and_their_username_is_free_again(): void
    {
        $this->actingAs($this->admin)->delete(route('admin.users.destroy', $this->waiter));

        Auth::logout();
        $this->post('/login', ['username' => 'محمد مرقة', 'password' => 'password']);
        $this->assertGuest();

        $this->actingAs($this->admin)
            ->post(route('admin.users.store'), [
                'name' => 'محمد مرقة',
                'username' => 'محمد مرقة',
                'password' => '123456789',
                'role' => 'waiter',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, User::withTrashed()->where('name', 'محمد مرقة')->count());
    }

    public function test_a_deleted_user_leaves_the_users_list(): void
    {
        $this->actingAs($this->admin)->delete(route('admin.users.destroy', $this->waiter));

        $this->actingAs($this->admin)
            ->get(route('admin.users.index'))
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Users/Index')
                ->has('users', 1)
                ->where('users.0.id', $this->admin->id));
    }

    public function test_someone_deleted_while_they_still_owe_stays_on_the_staff_accounts_until_settled(): void
    {
        $this->giveTheWaiterAHistory();
        $settled = User::factory()->create(['name' => 'موظف سابق']);

        $this->actingAs($this->admin)->delete(route('admin.users.destroy', $this->waiter));
        $this->actingAs($this->admin)->delete(route('admin.users.destroy', $settled));

        $this->actingAs($this->admin)
            ->get(route('admin.accounting.staff.index'))
            ->assertInertia(fn ($page) => $page
                ->has('staff', 2)
                ->where('staff.1.name', 'محمد مرقة')
                ->where('staff.1.deleted', true)
                ->where('staff.1.balance_cents', 5000));

        $this->actingAs($this->admin)
            ->get(route('admin.accounting.staff.show', $this->waiter))
            ->assertOk();
    }
}
