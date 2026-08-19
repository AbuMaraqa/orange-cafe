<?php

namespace Tests\Feature;

use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\RestaurantTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Several guests at one table each order from their own phone. Their items
 * have to land on the table's single open ticket — five separate orders would
 * reach the waiter as five confirmations, print as five kitchen tickets for
 * one table, and bill as five totals to add up by hand.
 */
class SharedTableOrderTest extends TestCase
{
    use RefreshDatabase;

    private function seedCatalog(): MenuItem
    {
        $this->seed(\Database\Seeders\LanguageSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->seed(\Database\Seeders\CatalogSeeder::class);

        // A product with a required modifier group ("Size") would fail
        // validation without a choice, which is a different test.
        return MenuItem::query()
            ->whereHas('product', fn ($q) => $q->whereDoesntHave(
                'modifierGroups',
                fn ($g) => $g->where('is_required', true)
            ))
            ->firstOrFail();
    }

    private function placeCustomerOrder(RestaurantTable $table, MenuItem $item, int $quantity = 1)
    {
        $response = $this->post(route('menu.orders.store'), [
            'table_id' => $table->id,
            'items' => [
                ['menu_item_id' => $item->id, 'quantity' => $quantity, 'modifiers' => []],
            ],
        ]);

        $response->assertSessionHasNoErrors();

        return $response;
    }

    public function test_guests_at_the_same_table_share_one_pending_order(): void
    {
        $item = $this->seedCatalog();
        $table = RestaurantTable::create(["label" => "T".fake()->unique()->numberBetween(1, 9999), "seats" => 4, "is_active" => true]);

        for ($guest = 0; $guest < 5; $guest++) {
            $this->placeCustomerOrder($table, $item)->assertRedirect();
        }

        $orders = Order::where('table_id', $table->id)->get();

        $this->assertCount(1, $orders, 'five guests must produce one ticket, not five');
        $this->assertSame('pending', $orders->first()->status);
        $this->assertSame(5, $orders->first()->items()->sum('quantity'));
    }

    public function test_the_shared_total_adds_up_across_guests(): void
    {
        $item = $this->seedCatalog();
        $table = RestaurantTable::create(["label" => "T".fake()->unique()->numberBetween(1, 9999), "seats" => 4, "is_active" => true]);

        $this->placeCustomerOrder($table, $item, 2);
        $this->placeCustomerOrder($table, $item, 3);

        $order = Order::where('table_id', $table->id)->firstOrFail();
        $lineTotal = $order->items()->sum("subtotal_cents");

        $this->assertSame(5, $order->items()->sum('quantity'));
        $this->assertSame($lineTotal, $order->total_cents, 'the ticket total must match its lines');
    }

    /**
     * Once the waiter has confirmed, the kitchen is already cooking. A later
     * addition is a new round: it needs its own confirmation and its own trip
     * to the pass, not a silent edit to something already printed.
     */
    public function test_a_confirmed_order_is_not_joined(): void
    {
        $item = $this->seedCatalog();
        $table = RestaurantTable::create(["label" => "T".fake()->unique()->numberBetween(1, 9999), "seats" => 4, "is_active" => true]);

        $this->placeCustomerOrder($table, $item);

        Order::where('table_id', $table->id)->firstOrFail()->update(['status' => 'confirmed']);

        $this->placeCustomerOrder($table, $item);

        $this->assertSame(2, Order::where('table_id', $table->id)->count());
        $this->assertSame(1, Order::where('table_id', $table->id)->where('status', 'pending')->count());
    }

    /**
     * Different tables must never merge, however close together the orders
     * are placed.
     */
    public function test_orders_from_different_tables_stay_separate(): void
    {
        $item = $this->seedCatalog();
        $one = RestaurantTable::create(["label" => "T".fake()->unique()->numberBetween(1, 9999), "seats" => 4, "is_active" => true]);
        $two = RestaurantTable::create(["label" => "T".fake()->unique()->numberBetween(1, 9999), "seats" => 4, "is_active" => true]);

        $this->placeCustomerOrder($one, $item);
        $this->placeCustomerOrder($two, $item);

        $this->assertSame(1, Order::where('table_id', $one->id)->count());
        $this->assertSame(1, Order::where('table_id', $two->id)->count());
    }
}
