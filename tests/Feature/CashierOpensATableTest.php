<?php

namespace Tests\Feature;

use App\Jobs\PrintCashierReceipt;
use App\Jobs\PrintOrderTicket;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Printer;
use App\Models\RestaurantTable;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The till can open a table, not only settle one.
 *
 * A walk-in who orders and pays at the counter, or a table nobody has got
 * to yet, should not wait for a waiter to be free before the kitchen hears
 * about it. The cashier types it in on the same screen the waiter uses.
 *
 * The part that is easy to get wrong is what happens *after* the order is
 * taken. An order entered by staff is born confirmed and prints straight
 * away; one that does not count as staff-entered is recorded as an
 * unreviewed customer order and prints nowhere — same button, same screen,
 * and nothing on either to say the kitchen was never told.
 */
class CashierOpensATableTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role): User
    {
        $this->seed(RoleSeeder::class);

        $user = User::create([
            'name' => ucfirst($role),
            'email' => $role.'@test.local',
            'password' => 'password',
            'locale' => 'ar',
            'email_verified_at' => now(),
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function printer(string $name, string $station): Printer
    {
        return Printer::create([
            'name' => $name,
            'station' => $station,
            'connection_type' => 'network',
            'ip_address' => '10.0.0.'.random_int(2, 250),
            'port' => 9100,
            'is_active' => true,
        ]);
    }

    private function table(string $label): RestaurantTable
    {
        return RestaurantTable::create([
            'label' => $label,
            'seats' => 4,
            'is_active' => true,
        ]);
    }

    /** A menu item with no required choices, so it can be ordered outright. */
    private function orderableItem(): MenuItem
    {
        $this->seed(LanguageSeeder::class);
        $this->seed(CatalogSeeder::class);

        return MenuItem::query()
            ->whereHas('product', fn ($q) => $q->whereDoesntHave(
                'modifierGroups',
                fn ($g) => $g->where('is_required', true)
            ))
            ->firstOrFail();
    }

    public function test_a_cashier_can_open_the_order_screen_for_a_table(): void
    {
        $item = $this->orderableItem();
        $table = $this->table('5');

        $this->actingAs($this->staff('cashier'))
            ->get(route('waiter.orders.create', ['table_id' => $table->id]))
            ->assertOk();

        $this->assertNotNull($item);
    }

    public function test_an_order_the_cashier_opens_is_confirmed_and_prints(): void
    {
        Queue::fake();

        $item = $this->orderableItem();
        $table = $this->table('5');
        $this->printer('المطبخ', 'kitchen');
        $this->printer('كاشير', 'cashier');

        $this->actingAs($this->staff('cashier'))->post(route('waiter.orders.store'), [
            'table_id' => $table->id,
            'items' => [['menu_item_id' => $item->id, 'quantity' => 2]],
        ])->assertRedirect();

        $order = Order::query()->latest('id')->firstOrFail();

        // Born confirmed: nobody is left to review an order the cashier
        // took while looking at the customer.
        $this->assertSame('confirmed', $order->status);
        $this->assertSame($table->id, $order->table_id);

        Queue::assertPushed(PrintOrderTicket::class);

        // Kitchens only, even from the till. Opening a table is not the
        // same moment as settling it, and the customer standing at the
        // counter has not asked for a receipt yet — the cashier presses
        // "طباعة للكاشير" when they do.
        Queue::assertNotPushed(PrintCashierReceipt::class);
    }

    public function test_the_till_can_still_print_the_bill_on_demand(): void
    {
        Queue::fake();

        $item = $this->orderableItem();
        $table = $this->table('5');
        $this->printer('كاشير', 'cashier');

        $cashier = $this->staff('cashier');

        $this->actingAs($cashier)->post(route('waiter.orders.store'), [
            'table_id' => $table->id,
            'items' => [['menu_item_id' => $item->id, 'quantity' => 1]],
        ]);

        Queue::assertNotPushed(PrintCashierReceipt::class);

        $order = Order::query()->latest('id')->firstOrFail();

        $this->actingAs($cashier)->post(route('orders.print-cashier', $order));

        Queue::assertPushed(PrintCashierReceipt::class, 1);
    }

    /**
     * The redirect is the part that breaks quietly: staff used to be sent
     * to /waiter after ordering, which the cashier cannot open — so a sale
     * that went through perfectly would answer with a 403.
     */
    public function test_the_cashier_lands_back_on_the_till_not_the_waiter_screen(): void
    {
        Queue::fake();

        $item = $this->orderableItem();
        $table = $this->table('5');

        $this->actingAs($this->staff('cashier'))->post(route('waiter.orders.store'), [
            'table_id' => $table->id,
            'items' => [['menu_item_id' => $item->id, 'quantity' => 1]],
        ])->assertRedirect(route('cashier.index', ['table' => $table->id]));
    }

    public function test_a_waiter_still_lands_back_on_the_floor(): void
    {
        Queue::fake();

        $item = $this->orderableItem();
        $table = $this->table('5');

        $this->actingAs($this->staff('waiter'))->post(route('waiter.orders.store'), [
            'table_id' => $table->id,
            'items' => [['menu_item_id' => $item->id, 'quantity' => 1]],
        ])->assertRedirect(route('waiter.index', ['table' => $table->id]));
    }

    public function test_a_cashier_can_add_a_round_to_a_table_already_running(): void
    {
        Queue::fake();

        $item = $this->orderableItem();
        $table = $this->table('5');

        $cashier = $this->staff('cashier');

        $this->actingAs($cashier)->post(route('waiter.orders.store'), [
            'table_id' => $table->id,
            'items' => [['menu_item_id' => $item->id, 'quantity' => 1]],
        ])->assertRedirect();

        $order = Order::query()->latest('id')->firstOrFail();

        $this->actingAs($cashier)->post(route('waiter.orders.addItems', $order->id), [
            'items' => [['menu_item_id' => $item->id, 'quantity' => 2]],
        ])->assertRedirect(route('cashier.index', ['table' => $table->id]));

        // Merged onto the line that was already there rather than added as
        // a second one — same rule as anywhere else (see OrderItemWriter).
        $this->assertSame(3, $order->fresh()->items->sum('quantity'));
    }

    public function test_the_kitchen_screen_cannot_open_a_table(): void
    {
        $this->orderableItem();

        $this->actingAs($this->staff('kitchen'))
            ->get(route('waiter.orders.create'))
            ->assertForbidden();
    }

    public function test_a_customer_order_is_still_left_for_a_waiter_to_confirm(): void
    {
        Queue::fake();

        $item = $this->orderableItem();
        $table = $this->table('5');

        // No login: the QR menu. Widening who counts as staff must not have
        // widened this.
        $this->post(route('menu.orders.store'), [
            'table_id' => $table->id,
            'items' => [['menu_item_id' => $item->id, 'quantity' => 1]],
        ])->assertRedirect();

        $this->assertSame('pending', Order::query()->latest('id')->firstOrFail()->status);
        Queue::assertNotPushed(PrintOrderTicket::class);
    }
}
