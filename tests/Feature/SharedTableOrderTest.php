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
        $this->seed(LanguageSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(CatalogSeeder::class);

        // A product with a required modifier group ("Size") would fail
        // validation without a choice, which is a different test.
        return MenuItem::query()
            ->whereHas('product', fn ($q) => $q->whereDoesntHave(
                'modifierGroups',
                fn ($g) => $g->where('is_required', true)
            ))
            ->firstOrFail();
    }

    /**
     * A member of staff accepting a round, the way the floor does it.
     *
     * Not an `update(['status' => 'confirmed'])`: approval is where a round
     * is folded into the table's bill and sent to the pass, so a test that
     * writes the column directly skips the behaviour it is checking.
     */
    private function approve(Order $order): void
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

        // Guests are not logged in; actingAs would otherwise carry into the
        // next order placed by this test and send it down the staff path.
        auth()->forgetGuards();
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
        $table = RestaurantTable::create(['label' => 'T'.fake()->unique()->numberBetween(1, 9999), 'seats' => 4, 'is_active' => true]);

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
        $table = RestaurantTable::create(['label' => 'T'.fake()->unique()->numberBetween(1, 9999), 'seats' => 4, 'is_active' => true]);

        $this->placeCustomerOrder($table, $item, 2);
        $this->placeCustomerOrder($table, $item, 3);

        $order = Order::where('table_id', $table->id)->firstOrFail();
        $lineTotal = $order->items()->sum('subtotal_cents');

        $this->assertSame(5, $order->items()->sum('quantity'));
        $this->assertSame($lineTotal, $order->total_cents, 'the ticket total must match its lines');
    }

    /**
     * A later round reaches the table's bill — once a member of staff has
     * approved it.
     *
     * Two rules meet here and both matter. The bill must end up whole: every
     * screen reads a table's ticket and stops there, so a second live ticket
     * becomes invisible while its food is cooked and served, and a real café
     * lost 57.00 to exactly that — a 45.00 bill presented for 102.00 of
     * food. And nothing a customer types may reach the kitchen unreviewed,
     * so the round arrives as its own pending order first.
     *
     * Approval is what joins them: it folds the round into the running
     * ticket and sends only that round to the pass. See
     * EveryCustomerRoundNeedsApprovalTest for the gate itself.
     */
    public function test_a_later_round_joins_the_running_ticket_once_approved(): void
    {
        $item = $this->seedCatalog();
        $table = RestaurantTable::create(['label' => 'T'.fake()->unique()->numberBetween(1, 9999), 'seats' => 4, 'is_active' => true]);

        $this->placeCustomerOrder($table, $item);

        $order = Order::where('table_id', $table->id)->firstOrFail();
        $order->update(['status' => 'confirmed']);
        $firstTotal = $order->total_cents;

        $this->placeCustomerOrder($table, $item);

        // Waiting for a person, and not on the bill yet.
        $round = Order::where('table_id', $table->id)->where('status', 'pending')->firstOrFail();
        $this->assertSame($firstTotal, $order->refresh()->total_cents);

        $this->approve($round);

        $this->assertSame(
            1,
            Order::where('table_id', $table->id)->whereIn('status', config('orders.active_statuses'))->count(),
            'the table must be left holding exactly one live ticket',
        );

        $order->refresh();

        $this->assertSame(2, $order->items()->sum('quantity'));
        $this->assertSame($firstTotal * 2, $order->total_cents, 'the approved round must be on the bill');
    }

    /**
     * The bill a table is presented with must account for every item it was
     * served — the regression test for the loss described above, asserted as
     * money rather than as row counts.
     */
    public function test_no_food_is_served_off_the_bill(): void
    {
        $item = $this->seedCatalog();
        $table = RestaurantTable::create(['label' => 'T'.fake()->unique()->numberBetween(1, 9999), 'seats' => 4, 'is_active' => true]);

        // Mains, approved and sent to the pass; then drinks; then dessert
        // once the kitchen had already plated the rest. Each round goes
        // through a member of staff, and each one has to end up on the bill.
        $this->placeCustomerOrder($table, $item, 3);
        $this->approve(Order::where('table_id', $table->id)->firstOrFail());

        $this->placeCustomerOrder($table, $item, 2);
        $this->approve(Order::where('table_id', $table->id)->where('status', 'pending')->firstOrFail());

        Order::where('table_id', $table->id)
            ->whereIn('status', config('orders.active_statuses'))
            ->firstOrFail()
            ->update(['status' => 'ready']);

        $this->placeCustomerOrder($table, $item);
        $this->approve(Order::where('table_id', $table->id)->where('status', 'pending')->firstOrFail());

        $live = Order::where('table_id', $table->id)
            ->whereIn('status', config('orders.active_statuses'))
            ->get();

        $this->assertCount(1, $live);

        $bill = $live->first();

        $this->assertSame(6, $bill->items()->sum('quantity'), 'every item served must appear on the bill');
        $this->assertSame(
            $bill->items()->sum('subtotal_cents'),
            $bill->total_cents,
            'the total charged must equal the lines it is made of',
        );
        // A round added after the pass called the table done puts it back in
        // the queue — there is food to cook again.
        $this->assertSame('confirmed', $bill->status);
    }

    /**
     * Different tables must never merge, however close together the orders
     * are placed.
     */
    public function test_orders_from_different_tables_stay_separate(): void
    {
        $item = $this->seedCatalog();
        $one = RestaurantTable::create(['label' => 'T'.fake()->unique()->numberBetween(1, 9999), 'seats' => 4, 'is_active' => true]);
        $two = RestaurantTable::create(['label' => 'T'.fake()->unique()->numberBetween(1, 9999), 'seats' => 4, 'is_active' => true]);

        $this->placeCustomerOrder($one, $item);
        $this->placeCustomerOrder($two, $item);

        $this->assertSame(1, Order::where('table_id', $one->id)->count());
        $this->assertSame(1, Order::where('table_id', $two->id)->count());
    }
}
