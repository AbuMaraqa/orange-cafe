<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Order;
use App\Models\PrintJob;
use App\Models\Printer;
use App\Models\RestaurantTable;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Nothing a customer orders reaches the kitchen without a member of staff.
 *
 * Not the first round and not the fifth. The rule is easy to state and easy
 * to lose: unifying a table's bill onto one ticket — which stops food being
 * served and never charged for — makes it tempting to let a later round join
 * that ticket directly, and then the customer's phone is talking to the
 * kitchen with nobody in between. This file holds both halves at once,
 * because each one alone is a bug.
 *
 * The mechanism: a round ordered after approval becomes its own pending
 * order, so it alerts the floor exactly as the first one did; approving it
 * folds it into the table's bill and sends only that round to the pass.
 */
class EveryCustomerRoundNeedsApprovalTest extends TestCase
{
    use RefreshDatabase;

    private MenuItem $item;

    private function setUpCafe(): RestaurantTable
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

        // A printer for whatever station this item routes to. Without one
        // every ticket lands as "no printer configured", which is a
        // different outcome from the one these tests are about — and it
        // hides whether the round would have printed at all.
        Printer::create([
            'name' => 'مطبخ',
            'station' => $this->item->effectiveStation(),
            'connection_type' => 'network',
            'ip_address' => '127.0.0.1',
            'port' => 9100,
            'is_active' => true,
        ]);

        return RestaurantTable::create([
            'label' => 'T'.fake()->unique()->numberBetween(1, 9999),
            'seats' => 4,
            'is_active' => true,
        ]);
    }

    private function staff(string $role): User
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

    /**
     * A guest ordering from their own phone — never authenticated.
     *
     * The logout matters: actingAs() persists across every later request in
     * the same test, so without it the "customer" round is posted as the
     * waiter who just approved the previous one, takes the staff path, and
     * the test passes while proving nothing.
     */
    private function guestOrders(RestaurantTable $table, int $quantity = 1): void
    {
        auth()->forgetGuards();

        $this->post(route('menu.orders.store'), [
            'table_id' => $table->id,
            'items' => [['menu_item_id' => $this->item->id, 'quantity' => $quantity, 'modifiers' => []]],
        ])->assertSessionHasNoErrors();
    }

    private function liveTickets(RestaurantTable $table)
    {
        return Order::where('table_id', $table->id)
            ->whereIn('status', config('orders.active_statuses'))
            ->get();
    }

    public function test_a_second_round_waits_for_staff_just_like_the_first(): void
    {
        $table = $this->setUpCafe();
        $waiter = $this->staff('waiter');

        $this->guestOrders($table, 2);

        $first = Order::where('table_id', $table->id)->firstOrFail();
        $this->assertSame('pending', $first->status);
        $this->assertSame(0, PrintJob::where('order_id', $first->id)->count(), 'nothing reaches the kitchen unreviewed');

        $this->actingAs($waiter)->post(route('orders.confirm', $first->id))->assertSessionHasNoErrors();
        $first->refresh();
        $this->assertSame('confirmed', $first->status);

        // The round that used to slip straight through.
        $this->guestOrders($table);

        $second = Order::where('table_id', $table->id)->where('status', 'pending')->first();

        $this->assertNotNull($second, 'a later round must come back as something to approve');
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(0, PrintJob::where('order_id', $second->id)->count());

        $first->refresh();
        $this->assertSame(2, $first->items()->sum('quantity'), 'and must not be on the bill yet');
    }

    /**
     * The bill stays whole. Approving the round moves it onto the ticket the
     * table is already running rather than leaving a second live one, which
     * is what let a table be served food it was never charged for.
     */
    public function test_approving_a_round_folds_it_into_the_one_bill(): void
    {
        $table = $this->setUpCafe();
        $waiter = $this->staff('waiter');

        $this->guestOrders($table, 2);
        $bill = Order::where('table_id', $table->id)->firstOrFail();
        $this->actingAs($waiter)->post(route('orders.confirm', $bill->id));

        $billTotal = $bill->refresh()->total_cents;

        $this->guestOrders($table);
        $round = Order::where('table_id', $table->id)->where('status', 'pending')->firstOrFail();
        $roundTotal = $round->total_cents;

        $this->actingAs($waiter)->post(route('orders.confirm', $round->id))->assertSessionHasNoErrors();

        $live = $this->liveTickets($table);
        $this->assertCount(1, $live, 'a table pays one bill, so it carries one ticket');

        $bill->refresh();
        $this->assertSame($bill->id, $live->first()->id, 'the ticket that survives is the one already running');
        $this->assertSame(3, $bill->items()->sum('quantity'));
        $this->assertSame($billTotal + $roundTotal, $bill->total_cents);
        $this->assertSame(
            $bill->items()->sum('subtotal_cents'),
            $bill->total_cents,
            'the total charged must equal the lines it is made of',
        );
    }

    /**
     * And only that round goes to the pass. The running ticket printed when
     * it was approved; sending it again would have the kitchen cook the
     * table's mains a second time.
     */
    public function test_only_the_new_round_is_sent_to_the_kitchen(): void
    {
        $table = $this->setUpCafe();
        $waiter = $this->staff('waiter');

        $this->guestOrders($table, 2);
        $bill = Order::where('table_id', $table->id)->firstOrFail();
        $this->actingAs($waiter)->post(route('orders.confirm', $bill->id));

        $ticketsAfterFirstApproval = PrintJob::where('order_id', $bill->id)->count();
        $this->assertGreaterThan(0, $ticketsAfterFirstApproval);

        $this->guestOrders($table);
        $round = Order::where('table_id', $table->id)->where('status', 'pending')->firstOrFail();
        $this->actingAs($waiter)->post(route('orders.confirm', $round->id));

        $tickets = PrintJob::where('order_id', $bill->id)->get();

        $this->assertGreaterThan($ticketsAfterFirstApproval, $tickets->count(), 'the round has to reach the kitchen');
        $this->assertSame(
            $tickets->pluck('idempotency_key')->unique()->count(),
            $tickets->count(),
            'the round needs its own key or the queue treats it as a duplicate and silently drops it',
        );
    }

    /**
     * The floor plan shows the bill, not the round waiting to be approved.
     *
     * Every screen reads a table's ticket and stops there. While a round
     * waits, the table has two, and the newest is the one with almost
     * nothing on it — so the tile and the till would quote a fraction of
     * what the table owes.
     */
    public function test_the_table_still_shows_the_bill_while_a_round_waits(): void
    {
        $table = $this->setUpCafe();
        $waiter = $this->staff('waiter');

        $this->guestOrders($table, 4);
        $bill = Order::where('table_id', $table->id)->firstOrFail();
        $this->actingAs($waiter)->post(route('orders.confirm', $bill->id));
        $billTotal = $bill->refresh()->total_cents;

        $this->guestOrders($table);

        $shown = $table->fresh()->activeOrder();

        $this->assertSame($bill->id, $shown->id);
        $this->assertSame($billTotal, $shown->total_cents);
    }

    /**
     * Staff are not gated. A waiter or cashier entering an order has already
     * reviewed it by definition, and making them approve their own typing
     * would be a tap that means nothing.
     */
    public function test_staff_still_add_to_a_running_ticket_directly(): void
    {
        $table = $this->setUpCafe();
        $cashier = $this->staff('cashier');

        $this->actingAs($cashier)->post(route('menu.orders.store'), [
            'table_id' => $table->id,
            'items' => [['menu_item_id' => $this->item->id, 'quantity' => 1, 'modifiers' => []]],
        ])->assertSessionHasNoErrors();

        $this->actingAs($cashier)->post(route('menu.orders.store'), [
            'table_id' => $table->id,
            'items' => [['menu_item_id' => $this->item->id, 'quantity' => 1, 'modifiers' => []]],
        ])->assertSessionHasNoErrors();

        $live = $this->liveTickets($table);

        $this->assertCount(1, $live);
        $this->assertSame(0, $live->where('status', 'pending')->count(), 'staff orders are reviewed already');
        $this->assertSame(2, $live->first()->items()->sum('quantity'));
    }

    /**
     * A waiter asked "what did we order?" can answer from one screen.
     *
     * The bill holds the food already on its way; the round the table asked
     * for two minutes ago is not on it yet and will not be until somebody
     * accepts it. Reading only the bill tells a table that ordered twice
     * that it ordered once — so the table carries both, and the total of the
     * two, which is the figure the guest actually means.
     */
    public function test_the_table_shows_the_bill_and_the_waiting_round_together(): void
    {
        $table = $this->setUpCafe();
        $waiter = $this->staff('waiter');

        $this->guestOrders($table, 2);
        $bill = Order::where('table_id', $table->id)->firstOrFail();
        $this->actingAs($waiter)->post(route('orders.confirm', $bill->id));
        $billTotal = $bill->refresh()->total_cents;

        $this->guestOrders($table);
        $round = Order::where('table_id', $table->id)->where('status', 'pending')->firstOrFail();

        $payload = $this->presentedTable($table);

        $this->assertSame($bill->id, $payload['active_order']['id'], 'the bill is still the bill');
        $this->assertCount(1, $payload['pending_rounds'], 'and the waiting round is listed beside it');
        $this->assertSame($round->id, $payload['pending_rounds'][0]['id']);
        $this->assertCount(1, $payload['pending_rounds'][0]['items']);
        $this->assertSame(
            $billTotal + $round->total_cents,
            $payload['requested_total_cents'],
        );
    }

    /**
     * A table whose only ticket is still awaiting review must not have it
     * listed twice: it is the bill and the waiting round at the same time,
     * and showing it in both places doubles every line on the screen.
     */
    public function test_a_first_round_is_not_listed_twice(): void
    {
        $table = $this->setUpCafe();

        $this->guestOrders($table, 2);

        $payload = $this->presentedTable($table);

        $this->assertNotNull($payload['active_order']);
        $this->assertCount(0, $payload['pending_rounds']);
        $this->assertSame($payload['active_order']['total_cents'], $payload['requested_total_cents']);
    }

    /**
     * The table exactly as the floor plan and the bill sheet receive it —
     * same trait, same eager loads, so this cannot pass while the screens
     * are being handed something different.
     *
     * @return array<string, mixed>
     */
    private function presentedTable(RestaurantTable $table): array
    {
        $presenter = new class
        {
            use \App\Http\Controllers\Concerns\PresentsTables;

            /** @return array<string, mixed> */
            public function present(RestaurantTable $table): array
            {
                return $this->presentTable($table);
            }
        };

        $fresh = RestaurantTable::with([
            'orders' => fn ($q) => $q
                ->whereIn('status', config('orders.active_statuses'))
                ->latest()
                ->with('items.modifiers'),
        ])->findOrFail($table->id);

        return $presenter->present($fresh);
    }
}
