<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A discount agreed at the till, taken off the bill as its own number.
 *
 * Repricing individual lines could already reduce a bill, but it makes the
 * record lie: it would say the coffee costs 8 when the menu says 12, and at
 * the end of the day nobody can tell a discount from a price change. Kept
 * separate, the lines stay honest and the reduction stays visible on the
 * receipt and in the figures.
 */
class CashierDiscountTest extends TestCase
{
    use RefreshDatabase;

    private ?User $cashier = null;

    private function cashier(): User
    {
        if ($this->cashier) {
            return $this->cashier;
        }

        $this->seed(RoleSeeder::class);

        $user = User::create([
            'name' => 'Cashier',
            'email' => 'cashier@test.local',
            'password' => 'password',
            'locale' => 'ar',
            'email_verified_at' => now(),
        ]);
        $user->assignRole('cashier');

        return $this->cashier = $user;
    }

    private function orderWithLine(int $unit = 1000, int $quantity = 2): Order
    {
        $order = Order::create([
            // See AdminEditsOrderLineTest: a line is editable only while
            // the order has not gone to the kitchen.
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

    private function discount(Order $order, int $cents, ?string $reason = null)
    {
        return $this->actingAs($this->cashier())->patch(
            route('orders.discount', $order->id),
            ['discount_cents' => $cents, 'discount_reason' => $reason]
        );
    }

    public function test_a_cashier_can_take_money_off_the_bill(): void
    {
        $order = $this->orderWithLine(1000, 2);

        $this->discount($order, 500)->assertRedirect();

        $order->refresh();

        $this->assertSame(500, $order->discount_cents);
        $this->assertSame(1500, $order->total_cents, 'the total owed follows the discount');
    }

    /**
     * The whole point of a separate discount: what the kitchen cooked and
     * what the menu charges stay recorded as they were.
     */
    public function test_the_lines_are_left_untouched(): void
    {
        $order = $this->orderWithLine(1000, 2);

        $this->discount($order, 500);

        $item = $order->items()->firstOrFail();

        $this->assertSame(1000, $item->unit_price_cents);
        $this->assertSame(2000, $item->subtotal_cents);
        $this->assertSame(2000, $order->fresh()->itemsSubtotalCents());
    }

    public function test_a_reason_is_recorded_with_it(): void
    {
        $order = $this->orderWithLine(1000, 2);

        $this->discount($order, 500, 'زبون دائم');

        $this->assertSame('زبون دائم', $order->fresh()->discount_reason);
    }

    public function test_a_discount_can_be_cleared(): void
    {
        $order = $this->orderWithLine(1000, 2);

        $this->discount($order, 500, 'خطأ');
        $this->discount($order, 0);

        $order->refresh();

        $this->assertSame(0, $order->discount_cents);
        $this->assertNull($order->discount_reason);
        $this->assertSame(2000, $order->total_cents);
    }

    /**
     * A discount bigger than the bill is a refund — a different transaction
     * with different paperwork, not something to fall out of a typo.
     */
    public function test_a_discount_larger_than_the_bill_is_refused(): void
    {
        $order = $this->orderWithLine(1000, 2);

        $this->discount($order, 2500)->assertSessionHasErrors('discount_cents');

        $this->assertSame(2000, $order->fresh()->total_cents);
    }

    public function test_a_negative_discount_is_refused(): void
    {
        $order = $this->orderWithLine(1000, 2);

        $this->discount($order, -500)->assertSessionHasErrors('discount_cents');
    }

    public function test_a_paid_order_is_refused(): void
    {
        $order = $this->orderWithLine(1000, 2);
        $order->update(['paid_at' => now(), 'amount_paid_cents' => 2000]);

        $this->discount($order, 500)->assertForbidden();

        $this->assertSame(0, $order->fresh()->discount_cents);
    }

    public function test_a_waiter_cannot_discount(): void
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

        $this->actingAs($waiter)
            ->patch(route('orders.discount', $order->id), ['discount_cents' => 500])
            ->assertForbidden();
    }

    /**
     * Editing a line afterwards must not quietly undo the discount — the
     * total is re-derived from the lines *and* the discount every time.
     */
    public function test_editing_a_line_afterwards_keeps_the_discount(): void
    {
        $order = $this->orderWithLine(1000, 2);
        $this->discount($order, 500);

        $this->seed(RoleSeeder::class);
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@test.local',
            'password' => 'password',
            'locale' => 'ar',
            'email_verified_at' => now(),
        ]);
        $admin->assignRole('admin');

        $this->actingAs($admin)->patch(
            route('orders.items.update', [$order->id, $order->items()->value('id')]),
            ['quantity' => 3]
        )->assertRedirect();

        $order->refresh();

        $this->assertSame(500, $order->discount_cents);
        $this->assertSame(2500, $order->total_cents, '3000 of drinks less the 500 discount');
    }

    /**
     * Removing items until the bill is smaller than the discount settles at
     * zero — the customer is not owed money for ordering less.
     */
    public function test_the_bill_never_goes_negative(): void
    {
        $order = $this->orderWithLine(1000, 2);
        $this->discount($order, 2000);

        $this->seed(RoleSeeder::class);
        $admin = User::create([
            'name' => 'Admin2',
            'email' => 'admin2@test.local',
            'password' => 'password',
            'locale' => 'ar',
            'email_verified_at' => now(),
        ]);
        $admin->assignRole('admin');

        $this->actingAs($admin)->patch(
            route('orders.items.update', [$order->id, $order->items()->value('id')]),
            ['quantity' => 1]
        );

        $order->refresh();

        $this->assertSame(0, $order->total_cents);
        $this->assertSame(1000, $order->discount_cents, 'the discount is capped at what is left to discount');
    }
}
