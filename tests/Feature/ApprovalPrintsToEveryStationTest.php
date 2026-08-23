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
 * Approving an order sends each station only its own food.
 *
 * A table ordering a steak and two coffees is two pieces of paper: one at
 * the grill, one at the bar. Neither should see the other's lines — a bar
 * printing a steak wastes paper and, worse, a grill that prints coffees
 * teaches the kitchen to skim past lines that are not theirs.
 *
 * The case this file exists for is the second one. A round ordered after
 * the table's bill was already approved arrives as its own pending order,
 * and approving it folds the round into the bill (see
 * EveryCustomerRoundNeedsApprovalTest). That fold moves rows between orders
 * — so "which lines are new" and "which station is each of them for" both
 * have to survive it, or a round reaches one station and silently not the
 * other.
 */
class ApprovalPrintsToEveryStationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, MenuItem> station => an item that routes there */
    private array $items = [];

    private function setUpTwoStations(): void
    {
        $this->seed(LanguageSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(CatalogSeeder::class);

        $byStation = MenuItem::query()
            ->whereHas('product', fn ($q) => $q->whereDoesntHave(
                'modifierGroups',
                fn ($g) => $g->where('is_required', true)
            ))
            ->get()
            ->groupBy(fn (MenuItem $item) => $item->effectiveStation());

        foreach ($byStation->take(2) as $station => $items) {
            Printer::create([
                'name' => "طابعة {$station}",
                'station' => $station,
                'connection_type' => 'network',
                'ip_address' => '127.0.0.1',
                'port' => 9100,
                'is_active' => true,
            ]);

            $this->items[$station] = $items->first();
        }

        $this->assertCount(2, $this->items, 'the seeded menu must span two stations for this to mean anything');
    }

    private function waiter(): User
    {
        $user = User::create([
            'name' => 'نادل',
            'email' => 'w'.fake()->unique()->numberBetween(1, 99999).'@test.local',
            'password' => 'password',
            'locale' => 'ar',
            'email_verified_at' => now(),
        ]);
        $user->assignRole('waiter');

        return $user;
    }

    private function guestOrdersFromBothStations(RestaurantTable $table): void
    {
        auth()->forgetGuards();

        $this->post(route('menu.orders.store'), [
            'table_id' => $table->id,
            'items' => collect($this->items)
                ->map(fn (MenuItem $item) => ['menu_item_id' => $item->id, 'quantity' => 1, 'modifiers' => []])
                ->values()
                ->all(),
        ])->assertSessionHasNoErrors();
    }

    private function approve(Order $order, User $waiter): void
    {
        $this->actingAs($waiter)
            ->post(route('orders.confirm', $order->id))
            ->assertSessionHasNoErrors();

        auth()->forgetGuards();
    }

    public function test_approval_queues_one_ticket_per_station(): void
    {
        $this->setUpTwoStations();
        $waiter = $this->waiter();
        $table = RestaurantTable::create(['label' => 'S1', 'seats' => 4, 'is_active' => true]);

        $this->guestOrdersFromBothStations($table);
        $order = Order::where('table_id', $table->id)->firstOrFail();

        $this->assertSame(0, PrintJob::count(), 'nothing prints before a person approves it');

        $this->approve($order, $waiter);

        $jobs = PrintJob::all();

        $this->assertCount(2, $jobs);
        $this->assertEqualsCanonicalizing(
            array_keys($this->items),
            $jobs->pluck('station')->all(),
            'each station gets its own ticket',
        );

        foreach ($jobs as $job) {
            $this->assertSame(1, $job->items_count, 'and only the lines that belong to it');
        }
    }

    /**
     * The round that arrives after the bill was already approved.
     *
     * Approving it folds its lines onto the running ticket and must still
     * split them by station. This is the fragile one: the lines change order
     * mid-flight, and a stale in-memory copy of the bill's items once made
     * the printer filter for ids it could not see and queue nothing at all.
     */
    public function test_a_later_round_still_reaches_both_stations(): void
    {
        $this->setUpTwoStations();
        $waiter = $this->waiter();
        $table = RestaurantTable::create(['label' => 'S2', 'seats' => 4, 'is_active' => true]);

        $this->guestOrdersFromBothStations($table);
        $bill = Order::where('table_id', $table->id)->firstOrFail();
        $this->approve($bill, $waiter);

        $firstRound = PrintJob::pluck('idempotency_key')->all();

        $this->guestOrdersFromBothStations($table);
        $round = Order::where('table_id', $table->id)->where('status', 'pending')->firstOrFail();
        $this->approve($round, $waiter);

        $newJobs = PrintJob::whereNotIn('idempotency_key', $firstRound)->get();

        $this->assertCount(2, $newJobs, 'the round reaches both stations, not just one');
        $this->assertEqualsCanonicalizing(
            array_keys($this->items),
            $newJobs->pluck('station')->all(),
        );

        foreach ($newJobs as $job) {
            $this->assertSame(1, $job->items_count, 'each slip carries this round only, not the whole running ticket');
        }
    }
}
