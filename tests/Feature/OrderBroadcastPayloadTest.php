<?php

namespace Tests\Feature;

use App\Events\OrderPlaced;
use App\Events\OrderStatusUpdated;
use App\Models\Order;
use App\Models\RestaurantTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A table that appears over the socket is built from the broadcast and
 * nothing else.
 *
 * There is no page load behind a live update to fill in the rest, so any
 * field the floor plan renders and the payload omits is simply missing on
 * screen. That is what "the order does not show in real time" turned out
 * to be: the tile reads `items_count`, `status_color` and `is_paid`, and
 * `OrderPlaced` sent none of the three — so a live order drew a tile with
 * a blank count and no paid/unpaid colour, and only looked right once
 * somebody reloaded and the server sent the full shape over HTTP.
 *
 * These tests pin the payload against the keys the component actually
 * reads, so the two cannot drift apart again silently.
 */
class OrderBroadcastPayloadTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Exactly what TableFloorPlan.vue and TableBillModal.vue read off
     * `table.active_order`. Mirrors PresentsTables::presentTable().
     *
     * @var array<int, string>
     */
    private const FLOOR_PLAN_KEYS = [
        'id', 'reference', 'table_id', 'customer_name', 'status', 'status_color',
        'guest_count', 'total_cents', 'discount_cents', 'is_paid',
        'amount_paid_cents', 'notes', 'items_count', 'items', 'created_at',
    ];

    private function orderOnATable(): Order
    {
        $table = RestaurantTable::create(['label' => '20', 'seats' => 4, 'is_active' => true]);

        $order = Order::create([
            'table_id' => $table->id,
            'status' => 'pending',
            'locale' => 'ar',
            'total_cents' => 5500,
        ]);

        $order->items()->create([
            'product_name' => 'شاورما عربي',
            'station' => 'kitchen',
            'unit_price_cents' => 2500,
            'quantity' => 2,
            'subtotal_cents' => 5000,
        ]);

        return $order->fresh('items');
    }

    public function test_a_new_order_broadcasts_everything_the_floor_plan_draws(): void
    {
        $payload = (new OrderPlaced($this->orderOnATable()))->broadcastWith()['order'];

        foreach (self::FLOOR_PLAN_KEYS as $key) {
            $this->assertArrayHasKey(
                $key,
                $payload,
                "The table tile reads `{$key}`; without it a live order renders incomplete."
            );
        }
    }

    public function test_the_item_count_is_the_number_the_tile_shows(): void
    {
        $payload = (new OrderPlaced($this->orderOnATable()))->broadcastWith()['order'];

        // Two of one dish, not one line — the tile counts plates.
        $this->assertSame(2, $payload['items_count']);
    }

    public function test_a_new_order_is_not_marked_paid(): void
    {
        $payload = (new OrderPlaced($this->orderOnATable()))->broadcastWith()['order'];

        // Absent, this was undefined on the client and the tile fell to its
        // unpaid colour by accident rather than on purpose.
        $this->assertFalse($payload['is_paid']);
    }

    public function test_the_order_carries_its_table_so_it_lands_on_one(): void
    {
        $order = $this->orderOnATable();
        $payload = (new OrderPlaced($order))->broadcastWith()['order'];

        // useStaffFloorPlan::relocate() places the order by this id. Without
        // it the order falls through to the untabled list instead of the
        // table the customer is sitting at.
        $this->assertSame($order->table_id, $payload['table_id']);
    }

    public function test_lines_carry_their_prices_for_the_bill_sheet(): void
    {
        $payload = (new OrderPlaced($this->orderOnATable()))->broadcastWith()['order'];
        $line = $payload['items'][0];

        foreach (['id', 'product_name', 'quantity', 'unit_price_cents', 'subtotal_cents'] as $key) {
            $this->assertArrayHasKey($key, $line, "Opening the bill on a live order needs `{$key}`.");
        }
    }

    /**
     * Adding a round to a running table changed its total on every screen
     * while the count beside it stayed on the number it had at page load.
     */
    public function test_a_status_update_refreshes_the_count_and_colour(): void
    {
        $order = $this->orderOnATable();

        $payload = (new OrderStatusUpdated($order))->broadcastWith()['order'];

        $this->assertArrayHasKey('items_count', $payload);
        $this->assertArrayHasKey('status_color', $payload);
        $this->assertSame(2, $payload['items_count']);
    }
}
