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
 * A guest may change their own order from their phone until a waiter
 * confirms it — and only their own, and not a moment after.
 *
 * Each phone is a session here: flushing it is a second guest picking up
 * their phone at the same table.
 */
class GuestEditsPendingOrderTest extends TestCase
{
    use RefreshDatabase;

    private RestaurantTable $table;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LanguageSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(CatalogSeeder::class);

        $this->table = RestaurantTable::create(['label' => 'T'.fake()->unique()->numberBetween(1, 9999), 'seats' => 4, 'is_active' => true]);
    }

    /**
     * Menu items that need no choice made, so an order of them is valid
     * without picking a size.
     */
    private function simpleItem(int $nth = 0): MenuItem
    {
        return MenuItem::query()
            ->whereHas('product', fn ($q) => $q->whereDoesntHave(
                'modifierGroups',
                fn ($g) => $g->where('is_required', true)
            ))
            ->orderBy('id')
            ->skip($nth)
            ->firstOrFail();
    }

    private function order(MenuItem $item, int $quantity = 1): Order
    {
        $this->post(route('menu.orders.store'), [
            'table_id' => $this->table->id,
            'items' => [
                ['menu_item_id' => $item->id, 'quantity' => $quantity, 'modifiers' => []],
            ],
        ])->assertSessionHasNoErrors();

        return Order::where('table_id', $this->table->id)->latest('id')->firstOrFail();
    }

    private function confirm(Order $order): void
    {
        $waiter = User::create([
            'name' => 'نادل',
            'email' => 'w'.fake()->unique()->numberBetween(1, 99999).'@test.local',
            'password' => 'password',
            'locale' => 'ar',
            'email_verified_at' => now(),
        ]);
        $waiter->assignRole('waiter');

        $this->actingAs($waiter)
            ->post(route('orders.confirm', $order->id))
            ->assertSessionHasNoErrors();

        auth()->forgetGuards();
    }

    public function test_a_guest_changes_the_quantity_of_their_own_line_before_confirmation(): void
    {
        $order = $this->order($this->simpleItem(), 3);
        $line = $order->items()->sole();

        $this->patch(route('menu.orders.items.update', [$order->id, $line->id]), ['quantity' => 1])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $line->refresh();
        $this->assertSame(1, $line->quantity);
        $this->assertSame($line->unit_price_cents, $line->subtotal_cents);
        $this->assertSame($line->unit_price_cents, $order->fresh()->total_cents);
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_removing_everything_they_ordered_withdraws_the_order(): void
    {
        $order = $this->order($this->simpleItem());
        $line = $order->items()->sole();

        $this->delete(route('menu.orders.items.destroy', [$order->id, $line->id]))
            ->assertRedirect(route('menu.index', ['table_id' => $this->table->id, 'table_label' => $this->table->label]));

        $this->assertModelMissing($line);
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_a_guest_cannot_touch_what_somebody_else_at_the_table_ordered(): void
    {
        $order = $this->order($this->simpleItem(0));
        $theirs = $order->items()->sole();

        // A second phone at the same table — joins the same pending ticket.
        $this->flushSession();
        $this->assertSame($order->id, $this->order($this->simpleItem(1))->id);
        $mine = $order->items()->whereKeyNot($theirs->id)->sole();

        $this->delete(route('menu.orders.items.destroy', [$order->id, $theirs->id]))->assertForbidden();
        $this->patch(route('menu.orders.items.update', [$order->id, $theirs->id]), ['quantity' => 5])->assertForbidden();

        $this->assertSame(1, $theirs->fresh()->quantity);

        // Their own line, on the very same ticket, is theirs to change.
        $this->patch(route('menu.orders.items.update', [$order->id, $mine->id]), ['quantity' => 2])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $mine->fresh()->quantity);
    }

    public function test_on_a_line_two_guests_ordered_into_only_their_own_share_comes_off(): void
    {
        $item = $this->simpleItem();
        $order = $this->order($item);

        $this->flushSession();
        $this->order($item);

        // One line reading ×2, not two lines of ×1 — see OrderItemWriter.
        $line = $order->items()->sole();
        $this->assertSame(2, $line->quantity);

        $this->delete(route('menu.orders.items.destroy', [$order->id, $line->id]))->assertRedirect();

        $this->assertSame(1, $line->fresh()->quantity);
        $this->assertSame('pending', $order->fresh()->status);

        // Their share is gone; what is left on the line is the other guest's.
        $this->delete(route('menu.orders.items.destroy', [$order->id, $line->id]))->assertForbidden();
        $this->assertSame(1, $line->fresh()->quantity);
    }

    public function test_once_the_waiter_confirms_the_guest_can_no_longer_change_it(): void
    {
        $order = $this->order($this->simpleItem(), 2);
        $line = $order->items()->sole();

        $this->confirm($order);

        $this->patch(route('menu.orders.items.update', [$order->id, $line->id]), ['quantity' => 1])
            ->assertSessionHasErrors('order');

        $this->delete(route('menu.orders.items.destroy', [$order->id, $line->id]))
            ->assertSessionHasErrors('order');

        $this->assertSame(2, $line->fresh()->quantity);
        $this->assertSame('confirmed', $order->fresh()->status);
    }

    public function test_the_order_page_offers_the_change_only_to_the_phone_that_ordered_it(): void
    {
        $order = $this->order($this->simpleItem(), 2);

        $this->get(route('menu.orders.show', $order->id))
            ->assertInertia(fn ($page) => $page
                ->where('order.editable', true)
                ->where('order.items.0.mine', 2));

        // Anybody else opening the same URL sees the order, not the buttons.
        $snapshot = session()->all();
        $this->flushSession();

        $this->get(route('menu.orders.show', $order->id))
            ->assertInertia(fn ($page) => $page->where('order.items.0.mine', 0));

        // And the phone that ordered it loses them once the waiter accepts.
        $this->withSession($snapshot);
        $this->confirm($order);

        $this->get(route('menu.orders.show', $order->id))
            ->assertInertia(fn ($page) => $page
                ->where('order.editable', false)
                ->where('order.items.0.mine', 0));
    }
}
