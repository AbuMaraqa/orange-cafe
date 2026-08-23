<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\RestaurantTable;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Clearing a table that did not happen.
 *
 * A customer changes their mind, a walk-in leaves, an order is rung in on
 * the wrong table. Before this existed the only way to clear one was to
 * mark it paid — which added money to the day's takings that nobody handed
 * over, so the cash box disagreed with the till by the value of every
 * abandoned table.
 *
 * The rule that matters most here is the refusal: a paid bill cannot be
 * cancelled. After money has changed hands the correction is a refund,
 * which is a different decision with its own record, and flipping a
 * settled order to "cancelled" would erase the takings it belongs to.
 */
class CancelTableTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role): User
    {
        $this->seed(RoleSeeder::class);

        $user = User::firstOrCreate(
            ['email' => $role.'@test.local'],
            ['name' => ucfirst($role), 'password' => 'password', 'locale' => 'ar', 'email_verified_at' => now()]
        );
        $user->assignRole($role);

        return $user;
    }

    private function table(string $label = '5'): RestaurantTable
    {
        return RestaurantTable::create(['label' => $label, 'seats' => 4, 'is_active' => true]);
    }

    private function orderOn(?RestaurantTable $table, string $status = 'pending'): Order
    {
        $order = Order::create([
            'table_id' => $table?->id,
            'status' => $status,
            'locale' => 'ar',
            'total_cents' => 2500,
        ]);

        $order->items()->create([
            'product_name' => 'برجر',
            'station' => 'kitchen',
            'unit_price_cents' => 2500,
            'quantity' => 1,
            'subtotal_cents' => 2500,
        ]);

        return $order->fresh('items');
    }

    private function cancel(User $as, Order $order)
    {
        return $this->actingAs($as)->patch(route('orders.cancel', $order));
    }

    public function test_a_waiter_cancels_an_unconfirmed_table(): void
    {
        $order = $this->orderOn($this->table());

        $this->cancel($this->staff('waiter'), $order)->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_a_cashier_can_cancel_too(): void
    {
        $order = $this->orderOn($this->table());

        $this->cancel($this->staff('cashier'), $order)->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_a_confirmed_table_can_still_be_cancelled(): void
    {
        // The customer left after ordering. It still has to be clearable —
        // the alternative is a table that stays occupied all night.
        $order = $this->orderOn($this->table(), status: 'confirmed');

        $this->cancel($this->staff('waiter'), $order)->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_the_table_reads_as_free_afterwards(): void
    {
        $table = $this->table();
        $order = $this->orderOn($table);

        $this->cancel($this->staff('cashier'), $order);

        $this->assertNull(
            $table->fresh()->activeOrder(),
            'A cancelled order must not keep occupying the table it was on.'
        );
    }

    public function test_a_paid_bill_is_never_cancelled(): void
    {
        $order = $this->orderOn($this->table());
        $order->update(['paid_at' => now(), 'amount_paid_cents' => 2500]);

        $this->cancel($this->staff('cashier'), $order)->assertSessionHasErrors('order');

        $this->assertNotSame('cancelled', $order->fresh()->status, 'The takings keep the order they belong to.');
    }

    public function test_the_kitchen_screen_cannot_cancel_a_table(): void
    {
        $order = $this->orderOn($this->table());

        $this->cancel($this->staff('kitchen'), $order)->assertForbidden();
    }

    /**
     * Cancelling cannot un-print a ticket, so when one has already come out
     * the message says so instead of implying the kitchen has been told.
     */
    public function test_cancelling_after_the_ticket_printed_warns_instead_of_reassuring(): void
    {
        $order = $this->orderOn($this->table(), status: 'confirmed');

        $printer = Printer::create([
            'name' => 'المطبخ', 'station' => 'kitchen', 'connection_type' => 'network',
            'ip_address' => '10.0.0.5', 'port' => 9100, 'is_active' => true,
        ]);

        PrintJob::create([
            'reference' => 'P-TEST-1',
            'order_id' => $order->id,
            'printer_id' => $printer->id,
            'station' => 'kitchen',
            'printer_name' => $printer->name,
            'kind' => PrintJob::KIND_KITCHEN,
            'status' => PrintJob::PRINTED,
            'printed_at' => now(),
            'idempotency_key' => 'kitchen:'.$order->id.':'.$printer->id.':kitchen:1',
        ]);

        $this->cancel($this->staff('waiter'), $order)
            ->assertSessionHas('warning')
            ->assertSessionMissing('success');
    }

    public function test_cancelling_an_unprinted_table_simply_succeeds(): void
    {
        $order = $this->orderOn($this->table());

        $this->cancel($this->staff('waiter'), $order)->assertSessionHas('success');
    }

    public function test_cancelling_twice_is_not_an_error(): void
    {
        $order = $this->orderOn($this->table());

        $this->cancel($this->staff('waiter'), $order);
        $this->cancel($this->staff('waiter'), $order)->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $order->fresh()->status);
    }
}
