<?php

namespace Tests\Feature;

use App\Events\OrderStatusUpdated;
use App\Models\Order;
use App\Models\RestaurantTable;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Taking part of a bill now and the rest later.
 *
 * The state had nowhere to live: a table paying half had to be either
 * settled — putting money in the day's takings that nobody handed over —
 * or left untouched, losing the record of what was actually collected.
 * Cashiers did the first, and the cash box then disagreed with the till by
 * the difference.
 *
 * Two rules carry the whole feature. Payments **accumulate**, because a
 * table paying in instalments hands money over more than once and each
 * amount is a fact rather than a replacement. And a bill **closes when it
 * is covered**, not when somebody presses a button — so a part-payment
 * that happens to cover the rest settles the table, and one that does not
 * leaves it open and owing.
 */
class PartialPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function cashier(): User
    {
        $this->seed(RoleSeeder::class);

        $user = User::firstOrCreate(
            ['email' => 'cashier@test.local'],
            ['name' => 'Cashier', 'password' => 'password', 'locale' => 'ar', 'email_verified_at' => now()]
        );
        $user->assignRole('cashier');

        return $user;
    }

    private function billOf(int $totalCents): Order
    {
        $table = RestaurantTable::create(['label' => '5', 'seats' => 4, 'is_active' => true]);

        $order = Order::create([
            'table_id' => $table->id,
            'status' => 'confirmed',
            'locale' => 'ar',
            'total_cents' => $totalCents,
        ]);

        $order->items()->create([
            'product_name' => 'شاورما',
            'station' => 'kitchen',
            'unit_price_cents' => $totalCents,
            'quantity' => 1,
            'subtotal_cents' => $totalCents,
        ]);

        return $order->fresh('items');
    }

    private function pay(Order $order, int $cents, bool $partial)
    {
        Queue::fake();

        return $this->actingAs($this->cashier())
            ->patch(route('orders.pay', $order), ['amount_paid_cents' => $cents, 'partial' => $partial]);
    }

    public function test_a_part_payment_leaves_the_bill_open_and_owing(): void
    {
        $order = $this->billOf(10000);

        $this->pay($order, 4000, partial: true)->assertSessionHasNoErrors();

        $order->refresh();

        $this->assertFalse($order->isPaid(), 'The table still owes money, so it is not settled.');
        $this->assertTrue($order->isPartiallyPaid());
        $this->assertSame(4000, $order->paidSoFarCents());
        $this->assertSame(6000, $order->outstandingCents());
    }

    public function test_payments_add_up_rather_than_replace_each_other(): void
    {
        $order = $this->billOf(10000);

        $this->pay($order, 4000, partial: true);
        $this->pay($order->fresh(), 3000, partial: true);

        $order->refresh();

        // 4000 replaced by 3000 would put 3000 in the day's takings for a
        // table that handed over 7000.
        $this->assertSame(7000, $order->paidSoFarCents());
        $this->assertSame(3000, $order->outstandingCents());
        $this->assertFalse($order->isPaid());
    }

    public function test_a_part_payment_that_covers_the_rest_settles_the_table(): void
    {
        $order = $this->billOf(10000);

        $this->pay($order, 6000, partial: true);
        $this->pay($order->fresh(), 4000, partial: true);

        $order->refresh();

        $this->assertTrue($order->isPaid(), 'Covered is covered — the table does not stay open out of pedantry.');
        $this->assertSame(10000, $order->paidSoFarCents());
        $this->assertSame(0, $order->outstandingCents());
        $this->assertNotNull($order->paid_by);
    }

    public function test_settling_outright_still_closes_the_bill_at_whatever_was_taken(): void
    {
        // The cashier rounds 102.00 down to 100.00 and is done with the
        // table. That was already the behaviour and must not change.
        $order = $this->billOf(10200);

        $this->pay($order, 10000, partial: false);

        $order->refresh();

        $this->assertTrue($order->isPaid());
        $this->assertSame(10000, $order->collectedCents());
    }

    public function test_overpaying_owes_nothing_back(): void
    {
        $order = $this->billOf(5000);

        $this->pay($order, 6000, partial: true);

        $order->refresh();

        $this->assertTrue($order->isPaid());
        // A tip, or a larger note. Reporting -1000 owing would feed a
        // negative back into the day's outstanding figure.
        $this->assertSame(0, $order->outstandingCents());
    }

    public function test_the_table_still_reads_as_occupied_while_it_owes(): void
    {
        $order = $this->billOf(10000);
        $table = $order->table;

        $this->pay($order, 4000, partial: true);

        $this->assertNotNull(
            $table->fresh()->activeOrder(),
            'A table that owes money has not left.'
        );
    }

    public function test_the_screens_are_told_what_is_still_owed(): void
    {
        $order = $this->billOf(10000);
        $this->pay($order, 4000, partial: true);

        $payload = (new OrderStatusUpdated($order->fresh()))->broadcastWith()['order'];

        // Without these a second cashier sees an unpaid bill for the full
        // amount and takes all of it again.
        $this->assertSame(4000, $payload['paid_so_far_cents']);
        $this->assertSame(6000, $payload['outstanding_cents']);
        $this->assertTrue($payload['is_partially_paid']);
    }
}
