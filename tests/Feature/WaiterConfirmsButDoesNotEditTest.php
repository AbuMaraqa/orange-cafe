<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Order;
use App\Models\RestaurantTable;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A waiter accepts orders. A waiter does not rewrite them.
 *
 * The floor's job is to decide whether an order is real and send it to the
 * kitchen — a decision about food. Changing what a line costs, or taking one
 * off the bill, is a decision about money, and money belongs to the owner.
 * The same line the café drew when it took the accounting screens away from
 * the till.
 *
 * Written down because the boundary is invisible from the outside: both
 * actions live on the same sheet, one button apart, and a well-meaning
 * change to "let the waiter fix a quantity" would hand over price editing in
 * the same move — updateItem takes quantity and price in one request.
 */
class WaiterConfirmsButDoesNotEditTest extends TestCase
{
    use RefreshDatabase;

    private MenuItem $item;

    private function table(): RestaurantTable
    {
        $this->seed(LanguageSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(CatalogSeeder::class);

        $this->item = MenuItem::query()
            ->whereHas('product', fn ($q) => $q->whereDoesntHave(
                'modifierGroups',
                fn ($g) => $g->where('is_required', true)
            ))
            ->firstOrFail();

        return RestaurantTable::create([
            'label' => 'W'.fake()->unique()->numberBetween(1, 9999),
            'seats' => 4,
            'is_active' => true,
        ]);
    }

    private function user(string $role): User
    {
        $user = User::create([
            'name' => 'مستخدم',
            'email' => $role.fake()->unique()->numberBetween(1, 99999).'@test.local',
            'password' => 'password',
            'locale' => 'ar',
            'email_verified_at' => now(),
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function customerOrder(RestaurantTable $table): Order
    {
        auth()->forgetGuards();

        $this->post(route('menu.orders.store'), [
            'table_id' => $table->id,
            'items' => [['menu_item_id' => $this->item->id, 'quantity' => 2, 'modifiers' => []]],
        ])->assertSessionHasNoErrors();

        return Order::where('table_id', $table->id)->firstOrFail();
    }

    public function test_a_waiter_can_confirm_an_order(): void
    {
        $table = $this->table();
        $order = $this->customerOrder($table);

        $this->actingAs($this->user('waiter'))
            ->post(route('orders.confirm', $order->id))
            ->assertSessionHasNoErrors();

        $this->assertSame('confirmed', $order->refresh()->status);
    }

    public function test_a_waiter_cannot_change_what_a_line_costs(): void
    {
        $table = $this->table();
        $order = $this->customerOrder($table);
        $line = $order->items()->firstOrFail();
        $was = $line->unit_price_cents;

        $this->actingAs($this->user('waiter'))
            ->patch(route('orders.items.update', [$order->id, $line->id]), ['unit_price_cents' => 1])
            ->assertForbidden();

        $this->assertSame($was, $line->refresh()->unit_price_cents, 'a refused edit must not have happened anyway');
    }

    public function test_a_waiter_cannot_change_a_quantity_either(): void
    {
        $table = $this->table();
        $order = $this->customerOrder($table);
        $line = $order->items()->firstOrFail();

        $this->actingAs($this->user('waiter'))
            ->patch(route('orders.items.update', [$order->id, $line->id]), ['quantity' => 99])
            ->assertForbidden();

        $this->assertSame(2, $line->refresh()->quantity);
    }

    public function test_a_waiter_cannot_take_a_line_off_the_bill(): void
    {
        $table = $this->table();
        $order = $this->customerOrder($table);
        $line = $order->items()->firstOrFail();

        $this->actingAs($this->user('waiter'))
            ->delete(route('orders.items.destroy', [$order->id, $line->id]))
            ->assertForbidden();

        $this->assertSame(1, $order->items()->count());
    }

    /**
     * The owner still can, up to the moment money changes hands — a price
     * the menu had wrong, or a plate that came out late and came off the
     * bill. After payment the recorded total has to keep matching what was
     * collected, which is a different rule and already enforced.
     */
    public function test_an_admin_can_still_correct_a_bill(): void
    {
        $table = $this->table();
        $order = $this->customerOrder($table);
        $line = $order->items()->firstOrFail();

        $this->actingAs($this->user('admin'))
            ->patch(route('orders.items.update', [$order->id, $line->id]), ['quantity' => 3])
            ->assertSessionHasNoErrors();

        $this->assertSame(3, $line->refresh()->quantity);
        $this->assertSame(
            $order->refresh()->items()->sum('subtotal_cents'),
            $order->total_cents,
            'the bill total must follow its lines',
        );
    }

    /**
     * The owner keeps the lines after confirmation too — their decision,
     * taken knowingly.
     *
     * Confirmation used to close them to everybody, because the food exists:
     * lowering a quantity does not un-cook the third plate, it only stops
     * anyone being charged for it, and the paper at the pass goes on saying
     * what it said. That disagreement is now allowed, so it is announced —
     * the edit screen warns, and updateItem() logs who changed what.
     *
     * The floor is a separate question and still answered no, by the route.
     */
    public function test_an_admin_edits_a_line_the_kitchen_already_has(): void
    {
        $table = $this->table();
        $order = $this->customerOrder($table);
        $line = $order->items()->firstOrFail();

        $order->update(['status' => 'confirmed']);

        $this->actingAs($this->user('admin'))
            ->patch(route('orders.items.update', [$order->id, $line->id]), ['quantity' => 3])
            ->assertSessionHasNoErrors();

        $this->assertSame(3, $line->refresh()->quantity);
        $this->assertSame(
            $order->refresh()->items()->sum('subtotal_cents'),
            $order->total_cents,
            'the bill total must follow its lines',
        );

        $this->actingAs($this->user('waiter'))
            ->patch(route('orders.items.update', [$order->id, $line->id]), ['quantity' => 1])
            ->assertForbidden();

        $this->assertSame(3, $line->refresh()->quantity, 'a refused edit must not have happened anyway');
    }

    /**
     * A finished order is a record — and the owner is the one person allowed
     * to correct the record.
     *
     * Cancelled or completed, it is what the day's figures are built from,
     * which is exactly why a wrong one has to be fixable: the alternative
     * the app used to offer was to leave the figures wrong. It is the
     * administrator's alone, from the orders list, and it is logged.
     */
    public function test_an_admin_edits_a_line_on_a_closed_order(): void
    {
        $table = $this->table();
        $order = $this->customerOrder($table);
        $line = $order->items()->firstOrFail();

        $admin = $this->user('admin');

        foreach (['completed', 'cancelled'] as $status) {
            $order->update(['status' => $status]);

            $this->actingAs($admin)
                ->patch(route('orders.items.update', [$order->id, $line->id]), ['quantity' => 9])
                ->assertRedirect();

            $this->assertSame(9, $line->refresh()->quantity);
        }

        $this->actingAs($admin)
            ->delete(route('orders.items.destroy', [$order->id, $line->id]))
            ->assertRedirect();

        $this->assertSame(0, $order->refresh()->items()->count());
    }

    /**
     * The floor keeps the old cutoff. A closed bill is out of reach at the
     * counter, where the pressure to rewrite one is highest and the person
     * doing it is standing in front of the customer who asked.
     */
    public function test_a_waiter_still_cannot_edit_a_closed_order(): void
    {
        $table = $this->table();
        $order = $this->customerOrder($table);
        $line = $order->items()->firstOrFail();

        $order->update(['status' => 'completed']);

        $this->actingAs($this->user('waiter'))
            ->patch(route('orders.items.update', [$order->id, $line->id]), ['quantity' => 9])
            ->assertForbidden();

        $this->assertSame(2, $line->refresh()->quantity, 'a refused edit must not have happened anyway');
    }

    /**
     * The correction that remains, and why it is the right one: it is
     * recorded, with a reason, instead of quietly rewriting what was cooked.
     */
    public function test_a_discount_is_still_available_after_confirmation(): void
    {
        $table = $this->table();
        $order = $this->customerOrder($table);
        $order->update(['status' => 'confirmed']);

        $this->actingAs($this->user('admin'))
            ->patch(route('orders.discount', $order->id), [
                'discount_cents' => 500,
                'discount_reason' => 'طبق تأخر',
            ])
            ->assertSessionHasNoErrors();

        $order->refresh();

        $this->assertSame(500, $order->discount_cents);
        $this->assertSame('طبق تأخر', $order->discount_reason);
    }
}
