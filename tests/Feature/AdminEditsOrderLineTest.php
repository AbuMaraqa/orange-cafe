<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An administrator can edit a line on a running order — a discount agreed at
 * the table, a price the menu had wrong, a plate taken off the bill, one more
 * coffee on a line that already says three.
 *
 * Deleting the line was the only way to do this before, which loses what the
 * customer actually ate and what the kitchen actually cooked.
 */
class AdminEditsOrderLineTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RoleSeeder::class);

        $user = User::create([
            'name' => 'Admin',
            'email' => 'admin@test.local',
            'password' => 'password',
            'locale' => 'ar',
            'email_verified_at' => now(),
        ]);
        $user->assignRole('admin');

        return $user;
    }

    private function orderWithLine(int $unit = 1000, int $quantity = 2): Order
    {
        // Pending on purpose. These tests are about the arithmetic of an
        // edit — recomputed totals, rejected zeroes, discounts that survive —
        // and lines are only editable while the order is still the floor's.
        // Once it is confirmed the ticket is in the kitchen and every edit is
        // refused, which is a different rule with its own tests.
        $order = Order::create([
            'status' => 'pending',
            'locale' => 'ar',
            'total_cents' => $unit * $quantity,
        ]);

        $order->items()->create([
            'product_name' => 'عصير برتقال',
            'station' => 'مشروبات',
            'unit_price_cents' => $unit,
            'quantity' => $quantity,
            'subtotal_cents' => $unit * $quantity,
        ]);

        return $order->fresh('items');
    }

    private function edit(Order $order, array $payload)
    {
        return $this->actingAs($this->admin())->patch(
            route('orders.items.update', [$order->id, $order->items->first()->id]),
            $payload
        );
    }

    private function reprice(Order $order, int $cents)
    {
        return $this->edit($order, ['unit_price_cents' => $cents]);
    }

    public function test_an_admin_can_change_a_line_price(): void
    {
        $order = $this->orderWithLine(1000, 2);

        $this->reprice($order, 750)->assertRedirect();

        $item = $order->items()->firstOrFail();

        $this->assertSame(750, $item->unit_price_cents);
        $this->assertSame(1500, $item->subtotal_cents, 'the line total must follow the unit price');
    }

    public function test_the_order_total_is_recomputed_from_its_lines(): void
    {
        $order = $this->orderWithLine(1000, 2);

        $this->reprice($order, 750);

        $order->refresh();

        $this->assertSame(1500, $order->total_cents);
        $this->assertSame($order->items()->sum('subtotal_cents'), $order->total_cents);
    }

    /**
     * Zero is allowed: a comped dish stays on the bill so the kitchen record
     * and the day's figures still show it was made.
     */
    public function test_a_line_can_be_taken_down_to_zero(): void
    {
        $order = $this->orderWithLine(1000, 2);

        $this->reprice($order, 0)->assertRedirect();

        $this->assertSame(0, $order->fresh()->total_cents);
    }

    public function test_a_negative_price_is_rejected(): void
    {
        $order = $this->orderWithLine(1000, 2);

        $this->reprice($order, -500)->assertSessionHasErrors('unit_price_cents');

        $this->assertSame(1000, $order->items()->firstOrFail()->unit_price_cents);
    }

    /**
     * The correction an owner actually has to make: the one found after the
     * table has left and the money is already counted.
     *
     * What was collected is not touched — it is a fact about what crossed
     * the counter, and rewriting it to match a corrected bill would put
     * money into the day's takings nobody handed over. The bill stays paid
     * for the same reason: a correction to the record is not the table
     * coming back.
     */
    public function test_an_admin_can_correct_a_paid_order(): void
    {
        $order = $this->orderWithLine(1000, 2);
        $order->update(['paid_at' => now(), 'amount_paid_cents' => 2000]);

        $this->reprice($order, 500)->assertRedirect();

        $order->refresh();

        $this->assertSame(500, $order->items()->firstOrFail()->unit_price_cents);
        $this->assertSame(1000, $order->total_cents, 'the bill follows its lines');
        $this->assertSame(2000, $order->amount_paid_cents, 'what was collected does not move');
        $this->assertTrue($order->isPaid(), 'correcting the record does not reopen the bill');
    }

    public function test_a_waiter_cannot_reprice(): void
    {
        $this->seed(RoleSeeder::class);

        $waiter = User::create([
            'name' => 'Waiter',
            'email' => 'waiter@test.local',
            'password' => 'password',
            'locale' => 'ar',
            'email_verified_at' => now(),
        ]);
        $waiter->assignRole('waiter');

        $order = $this->orderWithLine(1000, 2);

        $this->actingAs($waiter)->patch(
            route('orders.items.update', [$order->id, $order->items->first()->id]),
            ['unit_price_cents' => 1]
        )->assertForbidden();

        $this->assertSame(1000, $order->items()->firstOrFail()->unit_price_cents);
    }

    public function test_an_admin_can_change_a_line_quantity(): void
    {
        $order = $this->orderWithLine(1000, 2);

        $this->edit($order, ['quantity' => 5])->assertRedirect();

        $item = $order->items()->firstOrFail();

        $this->assertSame(5, $item->quantity);
        $this->assertSame(5000, $item->subtotal_cents, 'the line total must follow the quantity');
        $this->assertSame(5000, $order->fresh()->total_cents);
    }

    public function test_price_and_quantity_can_change_together(): void
    {
        $order = $this->orderWithLine(1000, 2);

        $this->edit($order, ['unit_price_cents' => 800, 'quantity' => 3])->assertRedirect();

        $item = $order->items()->firstOrFail();

        $this->assertSame(800, $item->unit_price_cents);
        $this->assertSame(3, $item->quantity);
        $this->assertSame(2400, $order->fresh()->total_cents);
    }

    /**
     * A line nobody is having is a removal, which leaves its own trail —
     * quantity zero would turn a bill line into a ghost that still prints.
     */
    public function test_a_quantity_of_zero_is_rejected(): void
    {
        $order = $this->orderWithLine(1000, 2);

        $this->edit($order, ['quantity' => 0])->assertSessionHasErrors('quantity');

        $this->assertSame(2, $order->items()->firstOrFail()->quantity);
    }

    public function test_an_edit_that_changes_nothing_is_rejected(): void
    {
        $order = $this->orderWithLine(1000, 2);

        $this->edit($order, [])->assertSessionHasErrors(['unit_price_cents', 'quantity']);
    }
}
