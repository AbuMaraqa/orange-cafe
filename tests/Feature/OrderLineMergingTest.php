<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Order;
use App\Models\RestaurantTable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ordering the same thing twice must read as one line with a bigger number,
 * not two lines saying the same thing. The kitchen adds them up by eye, the
 * waiter reads past them, and the customer has to trust a bill that lists
 * "orange juice" three times.
 */
class OrderLineMergingTest extends TestCase
{
    use RefreshDatabase;

    private function catalog(): MenuItem
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

    private function table(): RestaurantTable
    {
        return RestaurantTable::create([
            'label' => 'T'.fake()->unique()->numberBetween(1, 9999),
            'seats' => 4,
            'is_active' => true,
        ]);
    }

    private function order(RestaurantTable $table, MenuItem $item, int $quantity, ?string $notes = null)
    {
        return $this->post(route('menu.orders.store'), [
            'table_id' => $table->id,
            'items' => [[
                'menu_item_id' => $item->id,
                'quantity' => $quantity,
                'notes' => $notes,
                'modifiers' => [],
            ]],
        ]);
    }

    public function test_ordering_the_same_product_again_increases_the_existing_line(): void
    {
        $item = $this->catalog();
        $table = $this->table();

        $this->order($table, $item, 1);
        $this->order($table, $item, 2);

        $order = Order::where('table_id', $table->id)->firstOrFail();

        $this->assertSame(1, $order->items()->count(), 'one line, not two');
        $this->assertSame(3, $order->items()->sum('quantity'));
    }

    public function test_the_merged_line_keeps_its_subtotal_correct(): void
    {
        $item = $this->catalog();
        $table = $this->table();

        $this->order($table, $item, 1);
        $this->order($table, $item, 2);

        $line = Order::where('table_id', $table->id)->firstOrFail()->items()->firstOrFail();

        $this->assertSame($line->unit_price_cents * 3, $line->subtotal_cents);
    }

    public function test_the_order_total_still_matches_its_lines_after_merging(): void
    {
        $item = $this->catalog();
        $table = $this->table();

        $this->order($table, $item, 1);
        $this->order($table, $item, 2);

        $order = Order::where('table_id', $table->id)->firstOrFail();

        $this->assertSame($order->items()->sum('subtotal_cents'), $order->total_cents);
    }

    /**
     * A juice with a note is not the same drink as a juice without one —
     * merging them would drop an instruction the kitchen was given.
     */
    public function test_a_different_note_stays_on_its_own_line(): void
    {
        $item = $this->catalog();
        $table = $this->table();

        $this->order($table, $item, 1, 'بدون ثلج');
        $this->order($table, $item, 1);

        $this->assertSame(2, Order::where('table_id', $table->id)->firstOrFail()->items()->count());
    }
}
