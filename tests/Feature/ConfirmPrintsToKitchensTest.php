<?php

namespace Tests\Feature;

use App\Jobs\PrintCashierReceipt;
use App\Jobs\PrintOrderTicket;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Printer;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Confirming an order tells the kitchens, and only the kitchens.
 *
 * It used to print the bill at the till as well, on the reasoning that an
 * accepted order should be on paper everywhere at once. In a cafe that is
 * wrong: the table has just sat down, will order more, and will not pay for
 * an hour — so the receipt was paper nobody asked for, showing a total that
 * was about to change.
 *
 * The bill is a separate moment. It prints when somebody presses the till's
 * own print button (StaffOrderController::printForCashier); payment opens
 * the drawer without printing.
 *
 * One job per printer, deliberately — a dead station must not stop the
 * others, and that isolation is tested here too.
 */
class ConfirmPrintsToKitchensTest extends TestCase
{
    use RefreshDatabase;

    private function waiter(): User
    {
        $this->seed(RoleSeeder::class);

        // firstOrCreate: a test may act as the waiter more than once, and
        // each call asks for the person doing it.
        $user = User::firstOrCreate(
            ['email' => 'waiter@test.local'],
            [
                'name' => 'Waiter',
                'password' => 'password',
                'locale' => 'ar',
                'email_verified_at' => now(),
            ]
        );
        $user->assignRole('waiter');

        return $user;
    }

    private function printer(string $name, string $station): Printer
    {
        return Printer::create([
            'name' => $name,
            'station' => $station,
            'connection_type' => 'network',
            'ip_address' => '10.0.0.'.random_int(2, 250),
            'port' => 9100,
            'is_active' => true,
        ]);
    }

    private function pendingOrder(string $station = 'مشروبات'): Order
    {
        $order = Order::create([
            'status' => 'pending',
            'locale' => 'ar',
            'total_cents' => 1500,
        ]);

        $order->items()->create([
            'product_name' => 'عصير برتقال',
            'station' => $station,
            'unit_price_cents' => 1500,
            'quantity' => 1,
            'subtotal_cents' => 1500,
        ]);

        return $order->fresh('items');
    }

    private function confirm(Order $order)
    {
        return $this->actingAs($this->waiter())->post(route('orders.confirm', $order->id));
    }

    public function test_confirming_queues_the_kitchen_ticket_and_not_the_bill(): void
    {
        Queue::fake();

        $this->printer('مشروبات', 'مشروبات');
        // A till printer is configured and working — the bill still must not
        // print, because nobody asked for it.
        $this->printer('كاشير', 'cashier');

        $this->confirm($this->pendingOrder())->assertRedirect();

        Queue::assertPushed(PrintOrderTicket::class, 1);
        Queue::assertNotPushed(PrintCashierReceipt::class);
    }

    public function test_the_order_becomes_confirmed(): void
    {
        Queue::fake();

        $this->printer('مشروبات', 'مشروبات');
        $order = $this->pendingOrder();

        $this->confirm($order);

        $this->assertSame('confirmed', $order->fresh()->status);
    }

    /**
     * No till printer configured is not a reason to withhold the kitchen
     * ticket — the food still has to be cooked.
     */
    public function test_the_kitchen_still_prints_with_no_cashier_printer(): void
    {
        Queue::fake();

        $this->printer('مشروبات', 'مشروبات');

        $this->confirm($this->pendingOrder());

        Queue::assertPushed(PrintOrderTicket::class, 1);
        Queue::assertNotPushed(PrintCashierReceipt::class);
    }

    /**
     * An order whose items belong to no configured station prints nothing,
     * but is still recorded as unprinted — that record is the only thing
     * standing between "the grill has no printer" and food nobody cooks.
     */
    public function test_an_unroutable_station_prints_nothing_but_leaves_a_record(): void
    {
        Queue::fake();

        $this->printer('كاشير', 'cashier');

        $order = $this->pendingOrder('محطة-غير-موجودة');

        $this->confirm($order);

        Queue::assertNotPushed(PrintOrderTicket::class);
        Queue::assertNotPushed(PrintCashierReceipt::class);

        $this->assertDatabaseHas('print_jobs', [
            'order_id' => $order->id,
            'station' => 'محطة-غير-موجودة',
            'printer_id' => null,
        ]);
    }

    public function test_every_kitchen_station_gets_its_own_job(): void
    {
        Queue::fake();

        $this->printer('مشروبات', 'مشروبات');
        $this->printer('ماكولات', 'ماكولات');
        $this->printer('كاشير', 'cashier');

        $order = $this->pendingOrder();
        $order->items()->create([
            'product_name' => 'برجر',
            'station' => 'ماكولات',
            'unit_price_cents' => 3000,
            'quantity' => 1,
            'subtotal_cents' => 3000,
        ]);

        $this->confirm($order->fresh());

        Queue::assertPushed(PrintOrderTicket::class, 2);
        Queue::assertNotPushed(PrintCashierReceipt::class);
    }

    /**
     * The bill has to remain reachable — taking it off confirmation is only
     * correct if pressing the button still produces one.
     */
    public function test_the_bill_still_prints_when_the_till_asks_for_it(): void
    {
        Queue::fake();

        $this->printer('مشروبات', 'مشروبات');
        $this->printer('كاشير', 'cashier');

        $order = $this->pendingOrder();
        $this->confirm($order);

        Queue::assertNotPushed(PrintCashierReceipt::class);

        // The till's button, pressed by the till — the route is cashier-only.
        $cashier = User::firstOrCreate(
            ['email' => 'cashier@test.local'],
            ['name' => 'Cashier', 'password' => 'password', 'locale' => 'ar', 'email_verified_at' => now()]
        );
        $cashier->assignRole('cashier');

        $this->actingAs($cashier)->post(route('orders.print-cashier', $order->id));

        Queue::assertPushed(PrintCashierReceipt::class, 1);
    }

    /**
     * The *other* way an order is accepted: the waiter types it in at the
     * table, so it is born confirmed and never passes through confirm().
     *
     * It follows the same rule. The two paths are one event to everyone in
     * the cafe — an order has been taken — and a bill printing for one and
     * not the other would look like the till was malfunctioning at random.
     */
    public function test_an_order_the_waiter_types_does_not_print_the_bill_either(): void
    {
        Queue::fake();

        $this->seed(LanguageSeeder::class);
        $this->seed(CatalogSeeder::class);

        $this->printer('كاشير', 'cashier');

        $item = MenuItem::query()
            ->whereHas('product', fn ($q) => $q->whereDoesntHave(
                'modifierGroups',
                fn ($g) => $g->where('is_required', true)
            ))
            ->firstOrFail();

        $this->actingAs($this->waiter())->post(route('waiter.orders.store'), [
            'items' => [['menu_item_id' => $item->id, 'quantity' => 1]],
        ])->assertRedirect();

        Queue::assertNotPushed(PrintCashierReceipt::class);
    }
}
