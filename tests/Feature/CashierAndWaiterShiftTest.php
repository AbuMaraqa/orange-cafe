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
 * An evening on the floor, from both sides of it.
 *
 * The other tests each check one rule. This one asks a different and harder
 * question: after everything that has been changed — one ticket per table,
 * and no money permissions for the cashier — can these two people still do
 * their jobs from beginning to end?
 *
 * That question is worth its own file because the two changes were made for
 * opposite reasons and can break each other. Locking the cashier out of the
 * accounting screens is only correct if taking payment was never one of
 * them; unifying a table's tickets is only correct if the till still charges
 * for all of it. A test per rule would have passed while the combination
 * left a cashier who could see a bill and not settle it.
 *
 * So each test here walks a real sequence and checks the money at the end,
 * rather than asserting a status code and stopping.
 */
class CashierAndWaiterShiftTest extends TestCase
{
    use RefreshDatabase;

    private function seedCatalog(): MenuItem
    {
        $this->seed(LanguageSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(CatalogSeeder::class);

        return MenuItem::query()
            ->whereHas('product', fn ($q) => $q->whereDoesntHave(
                'modifierGroups',
                fn ($g) => $g->where('is_required', true)
            ))
            ->firstOrFail();
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

    private function table(): RestaurantTable
    {
        return RestaurantTable::create([
            'label' => 'T'.fake()->unique()->numberBetween(1, 9999),
            'seats' => 4,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<int, array{0:MenuItem, 1:int}>  $lines
     */
    private function order(RestaurantTable $table, array $lines, ?User $as = null)
    {
        // Exception handling is left on: these tests assert on refusals as
        // well as on successes, and turning it off turns a 403 into a thrown
        // exception that no assertForbidden() ever gets to see.
        $request = $as ? $this->actingAs($as) : $this;

        return $request->post(route('menu.orders.store'), [
            'table_id' => $table->id,
            'items' => array_map(fn ($line) => [
                'menu_item_id' => $line[0]->id,
                'quantity' => $line[1],
                'modifiers' => [],
            ], $lines),
        ]);
    }

    // -----------------------------------------------------------------
    // The cashier
    // -----------------------------------------------------------------

    /**
     * The whole job, in order: open a table, add to it, discount it, take
     * the money.
     *
     * None of it touches the accounting permissions, which is the claim the
     * config file makes in a comment. A comment cannot fail; this can.
     */
    public function test_a_cashier_can_work_a_table_from_opening_to_payment(): void
    {
        $item = $this->seedCatalog();
        $cashier = $this->user('cashier');
        $table = $this->table();

        $this->order($table, [[$item, 2]], $cashier)->assertRedirect();

        $order = Order::where('table_id', $table->id)->firstOrFail();
        $gross = $order->total_cents;

        $this->assertGreaterThan(0, $gross);
        $this->assertSame('confirmed', $order->status, 'A cashier opening a table has already reviewed it.');

        // A round added later, from the till.
        $this->actingAs($cashier)
            ->post(route('waiter.orders.addItems', $order->id), [
                'items' => [['menu_item_id' => $item->id, 'quantity' => 1, 'modifiers' => []]],
            ])
            ->assertRedirect();

        $order->refresh();
        $this->assertSame(3, $order->items()->sum('quantity'));

        $withRound = $order->total_cents;

        $this->actingAs($cashier)
            ->patch(route('orders.discount', $order->id), ['discount_cents' => 100, 'discount_reason' => 'تقريب'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $order->refresh();

        // total_cents is the amount owed, already net of the discount —
        // Order::recalculateTotal() writes it as subtotal minus discount.
        // Worth pinning down: subtracting the discount from it again is an
        // easy mistake to make anywhere that prints or collects a bill, and
        // it undercharges by exactly the discount a second time.
        $this->assertSame($withRound - 100, $order->total_cents);
        $this->assertSame(100, $order->discount_cents);

        $due = $order->total_cents;

        $this->actingAs($cashier)
            ->patch(route('orders.pay', $order->id), ['amount_paid_cents' => $due])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $order->refresh();

        $this->assertTrue($order->isPaid(), 'A cashier who cannot settle a bill cannot run a till.');
        $this->assertSame($due, $order->amount_paid_cents);
    }

    /**
     * The bill a cashier is handed covers every round the table had.
     *
     * This is the regression that cost a real café 57.00: the table's first
     * ticket became invisible behind its second, and the till charged for
     * the second alone while the kitchen had cooked both.
     */
    public function test_the_till_charges_for_every_round_a_table_had(): void
    {
        $item = $this->seedCatalog();
        $cashier = $this->user('cashier');
        $table = $this->table();

        // Three rounds from the table's own phones, each approved by a
        // member of staff — the exact shape of the evening that lost the
        // money, now with the gate that stops a customer reaching the
        // kitchen unreviewed.
        $waiter = $this->user('waiter');

        $approveTheWaitingRound = function () use ($table, $waiter) {
            $round = Order::where('table_id', $table->id)->where('status', 'pending')->firstOrFail();

            $this->actingAs($waiter)->post(route('orders.confirm', $round->id))->assertSessionHasNoErrors();

            // Guests are not logged in — without this the next round would
            // be posted as the waiter and take the staff path instead.
            auth()->forgetGuards();
        };

        $this->order($table, [[$item, 1]]);
        $approveTheWaitingRound();

        $this->order($table, [[$item, 1]]);
        $approveTheWaitingRound();

        Order::where('table_id', $table->id)
            ->whereIn('status', config('orders.active_statuses'))
            ->firstOrFail()
            ->update(['status' => 'ready']);

        $this->order($table, [[$item, 1]]);
        $approveTheWaitingRound();

        $live = Order::where('table_id', $table->id)
            ->whereIn('status', config('orders.active_statuses'))
            ->get();

        $this->assertCount(1, $live, 'A table pays one bill, so it carries one ticket.');

        $bill = $live->first();
        $this->assertSame(3, $bill->items()->sum('quantity'));

        $this->actingAs($cashier)
            ->patch(route('orders.pay', $bill->id))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $bill->refresh();

        // Paying with no amount hands over the whole bill — so what the
        // till took is exactly what the table was served.
        $this->assertSame(
            $bill->items()->sum('subtotal_cents'),
            $bill->amount_paid_cents,
            'Every item served must have been paid for.',
        );
    }

    // -----------------------------------------------------------------
    // The waiter
    // -----------------------------------------------------------------

    /**
     * A waiter's evening: take the order, confirm it, add a round, and hand
     * the table over to the till without ever handling money.
     */
    public function test_a_waiter_runs_the_floor_without_touching_money(): void
    {
        $item = $this->seedCatalog();
        $waiter = $this->user('waiter');
        $table = $this->table();

        $this->order($table, [[$item, 2]], $waiter)->assertRedirect();

        $order = Order::where('table_id', $table->id)->firstOrFail();

        $this->actingAs($waiter)
            ->post(route('waiter.orders.addItems', $order->id), [
                'items' => [['menu_item_id' => $item->id, 'quantity' => 1, 'modifiers' => []]],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame(3, $order->items()->sum('quantity'));

        // Where the floor stops and the till begins.
        $this->actingAs($waiter)->patch(route('orders.pay', $order->id))->assertForbidden();
        $this->actingAs($waiter)->patch(route('orders.discount', $order->id), ['discount_cents' => 500])->assertForbidden();

        $order->refresh();
        $this->assertFalse($order->isPaid(), 'A refused payment must not have been taken anyway.');
        $this->assertSame(0, $order->discount_cents ?? 0);
    }

    /**
     * A waiter reaching a table from the dropdown rather than the floor plan
     * still lands on its running ticket.
     *
     * The screen only offers "add to the running order" when the table was
     * opened from the plan. Picking the same table from the list posted to a
     * different route, and that route used to open a second ticket — so
     * whether the money stayed on one bill depended on which way the waiter
     * happened to arrive. The decision belongs to the table, not to the
     * navigation.
     */
    public function test_a_waiter_cannot_split_a_table_by_arriving_a_different_way(): void
    {
        $item = $this->seedCatalog();
        $waiter = $this->user('waiter');
        $table = $this->table();

        $this->order($table, [[$item, 1]], $waiter);
        $this->order($table, [[$item, 1]], $waiter);

        $this->assertSame(
            1,
            Order::where('table_id', $table->id)
                ->whereIn('status', config('orders.active_statuses'))
                ->count(),
            'Two ways in, one bill.',
        );
    }

    /**
     * A table that has settled up starts fresh for the next guests.
     *
     * The counterweight to everything above: joining is right while a table
     * is still sitting, and wrong the moment it has paid. Without this the
     * "one ticket per table" rule would reopen a closed till transaction and
     * bill new arrivals for the last group's dinner.
     */
    public function test_a_paid_table_starts_a_new_bill_for_the_next_guests(): void
    {
        $item = $this->seedCatalog();
        $cashier = $this->user('cashier');
        $table = $this->table();

        $this->order($table, [[$item, 2]], $cashier);
        $first = Order::where('table_id', $table->id)->firstOrFail();

        $this->actingAs($cashier)->patch(route('orders.pay', $first->id))->assertRedirect();
        $first->refresh();
        $this->assertTrue($first->isPaid());

        $this->order($table, [[$item, 1]]);

        $second = Order::where('table_id', $table->id)->whereNull('paid_at')->firstOrFail();

        $this->assertNotSame($first->id, $second->id, 'New guests, new bill.');
        $this->assertSame(1, $second->items()->sum('quantity'), 'They must not inherit the last table\'s dinner.');
    }
}
