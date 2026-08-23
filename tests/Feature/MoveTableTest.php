<?php

namespace Tests\Feature;

use App\Jobs\PrintOrderTicket;
use App\Models\Order;
use App\Models\Printer;
use App\Models\RestaurantTable;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Moving a running tab to another table, from the floor or from the till.
 *
 * Two different things wear the same button. If the target table is free
 * the tab simply relocates. If it already has one, the two parties have
 * joined up and the bills merge — which is destructive, so the rules about
 * when it is refused matter more than the happy path.
 *
 * Nothing merges across a payment. Folding a paid bill into an unpaid one
 * (or the reverse) leaves a total nobody can account for, so both sides
 * have to still be open.
 */
class MoveTableTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role): User
    {
        $this->seed(RoleSeeder::class);

        $user = User::firstOrCreate(
            ['email' => $role.'@test.local'],
            [
                'name' => ucfirst($role),
                'password' => 'password',
                'locale' => 'ar',
                'email_verified_at' => now(),
            ]
        );
        $user->assignRole($role);

        return $user;
    }

    private function table(string $label): RestaurantTable
    {
        return RestaurantTable::create(['label' => $label, 'seats' => 4, 'is_active' => true]);
    }

    /**
     * The station every order in this file is routed to. Without it the
     * merge reprints nothing at all — a station with no printer records an
     * unroutable job instead of queueing one.
     */
    private function barPrinter(): Printer
    {
        return Printer::create([
            'name' => 'بار',
            'station' => 'bar',
            'connection_type' => 'network',
            'ip_address' => '10.0.0.9',
            'port' => 9100,
            'is_active' => true,
        ]);
    }

    private function orderOn(?RestaurantTable $table, int $totalCents = 1500, string $status = 'confirmed'): Order
    {
        $order = Order::create([
            'table_id' => $table?->id,
            'status' => $status,
            'locale' => 'ar',
            'total_cents' => $totalCents,
        ]);

        $order->items()->create([
            'product_name' => 'عصير برتقال',
            'station' => 'bar',
            'unit_price_cents' => $totalCents,
            'quantity' => 1,
            'subtotal_cents' => $totalCents,
        ]);

        return $order->fresh('items');
    }

    private function move(User $as, Order $order, RestaurantTable $to)
    {
        return $this->actingAs($as)->patch(route('orders.move-table', $order), ['table_id' => $to->id]);
    }

    public function test_a_waiter_moves_a_tab_to_a_free_table(): void
    {
        $from = $this->table('3');
        $to = $this->table('7');
        $order = $this->orderOn($from);

        $this->move($this->staff('waiter'), $order, $to)->assertSessionHasNoErrors();

        $this->assertSame($to->id, $order->fresh()->table_id);
    }

    public function test_a_cashier_moves_a_tab_to_a_free_table(): void
    {
        $from = $this->table('3');
        $to = $this->table('7');
        $order = $this->orderOn($from);

        $this->move($this->staff('cashier'), $order, $to)->assertSessionHasNoErrors();

        $this->assertSame($to->id, $order->fresh()->table_id);
    }

    public function test_the_kitchen_screen_cannot_move_a_table(): void
    {
        $order = $this->orderOn($this->table('3'));

        $this->move($this->staff('kitchen'), $order, $this->table('7'))->assertForbidden();
    }

    public function test_two_parties_joining_up_merges_the_bills(): void
    {
        Queue::fake();
        $this->barPrinter();

        $from = $this->table('3');
        $to = $this->table('7');

        $moving = $this->orderOn($from, 1500);
        $staying = $this->orderOn($to, 2000);

        $this->move($this->staff('cashier'), $moving, $to)->assertSessionHasNoErrors();

        $staying->refresh();

        $this->assertSame(3500, $staying->total_cents, 'The merged bill is both totals.');
        $this->assertSame(2, $staying->items()->count(), 'Both parties\' items end up on one ticket.');
        $this->assertSame('cancelled', $moving->fresh()->status, 'The tab that moved is closed, not left running.');
        $this->assertSame(0, $moving->fresh()->items()->count());

        // The kitchen is holding a ticket that no longer describes the
        // table, so the merged order reprints in full.
        Queue::assertPushed(PrintOrderTicket::class);
    }

    public function test_a_paid_tab_is_not_moved(): void
    {
        $order = $this->orderOn($this->table('3'));
        $order->update(['paid_at' => now()]);

        $this->move($this->staff('cashier'), $order, $this->table('7'))
            ->assertSessionHasErrors('table_id');

        $this->assertSame($order->table_id, $order->fresh()->table_id);
    }

    public function test_a_tab_is_not_merged_into_a_paid_one(): void
    {
        $to = $this->table('7');
        $moving = $this->orderOn($this->table('3'), 1500);
        $settled = $this->orderOn($to, 2000);
        $settled->update(['paid_at' => now()]);

        $this->move($this->staff('cashier'), $moving, $to)->assertSessionHasErrors('table_id');

        $this->assertSame(2000, $settled->fresh()->total_cents, 'A settled bill is never added to.');
        $this->assertNotSame('cancelled', $moving->fresh()->status);
    }

    public function test_moving_a_tab_to_the_table_it_is_already_on_is_refused(): void
    {
        $table = $this->table('3');
        $order = $this->orderOn($table);

        $this->actingAs($this->staff('cashier'))
            ->patch(route('orders.move-table', $order), ['table_id' => $table->id])
            ->assertSessionHasErrors('table_id');
    }

    /**
     * An unpaid order the kitchen has finished with still holds the table.
     * Treating it as free would move a tab on top of it and quietly orphan
     * a bill nobody has collected.
     */
    public function test_a_completed_but_unpaid_tab_still_counts_as_occupying_its_table(): void
    {
        Queue::fake();

        $to = $this->table('7');
        $moving = $this->orderOn($this->table('3'), 1500);
        $waitingToPay = $this->orderOn($to, 2000, status: 'completed');

        $this->move($this->staff('cashier'), $moving, $to)->assertSessionHasNoErrors();

        $this->assertSame(3500, $waitingToPay->fresh()->total_cents);
        $this->assertSame('cancelled', $moving->fresh()->status);
    }

    public function test_an_untabled_order_can_be_given_a_table(): void
    {
        $order = $this->orderOn(null);
        $to = $this->table('7');

        $this->move($this->staff('cashier'), $order, $to)->assertSessionHasNoErrors();

        $this->assertSame($to->id, $order->fresh()->table_id);
    }

    public function test_a_discount_travels_with_the_bill_it_belongs_to(): void
    {
        Queue::fake();
        $this->barPrinter();

        $to = $this->table('7');
        $moving = $this->orderOn($this->table('3'), 1500);
        $moving->update(['discount_cents' => 300]);
        $staying = $this->orderOn($to, 2000);
        $staying->update(['discount_cents' => 200]);

        $this->move($this->staff('cashier'), $moving, $to)->assertSessionHasNoErrors();

        $this->assertSame(500, $staying->fresh()->discount_cents, 'Both discounts survive the merge.');
    }
}
