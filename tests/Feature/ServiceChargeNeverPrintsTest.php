<?php

namespace Tests\Feature;

use App\Jobs\PrintOrderTicket;
use App\Models\Order;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\ServiceCharge;
use App\Models\User;
use App\Services\KitchenPrinterService;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Money on the bill that nobody cooks.
 *
 * A table held past two hours is charged for the table, not for a dish, and
 * the pass must never hear about it. A service charge on kitchen paper is a
 * ticket a chef has to decide to ignore — printed in the same column, in
 * the same run, as food that has to be made — and the failure it invites is
 * the expensive one: the real line beside it read as part of the same
 * mistake and skipped.
 *
 * The line still behaves like a line everywhere else: it is on the bill, in
 * the total, and in the day's takings.
 */
class ServiceChargeNeverPrintsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(LanguageSeeder::class);
    }

    private function cashier(): User
    {
        $user = User::create([
            'name' => 'كاشير',
            'email' => 'cashier'.fake()->unique()->numberBetween(1, 99999).'@test.local',
            'password' => 'password',
            'locale' => 'ar',
            'email_verified_at' => now(),
        ]);
        $user->assignRole('cashier');

        return $user;
    }

    private function waiter(): User
    {
        $user = User::create([
            'name' => 'نادل',
            'email' => 'waiter'.fake()->unique()->numberBetween(1, 99999).'@test.local',
            'password' => 'password',
            'locale' => 'ar',
            'email_verified_at' => now(),
        ]);
        $user->assignRole('waiter');

        return $user;
    }

    private function charge(int $cents = 1000): ServiceCharge
    {
        return ServiceCharge::create([
            'amount_cents' => $cents,
            'is_active' => true,
            'ar' => ['name' => 'خدمة طاولة'],
            'en' => ['name' => 'Table service'],
        ]);
    }

    private function runningOrder(): Order
    {
        $order = Order::create([
            'status' => 'confirmed',
            'locale' => 'ar',
            'total_cents' => 1500,
        ]);

        $order->items()->create([
            'product_name' => 'عصير برتقال',
            'station' => 'مشروبات',
            'unit_price_cents' => 1500,
            'quantity' => 1,
            'subtotal_cents' => 1500,
        ]);

        return $order->fresh('items');
    }

    private function printer(string $station = 'مشروبات'): Printer
    {
        return Printer::create([
            'name' => $station,
            'station' => $station,
            'connection_type' => 'network',
            'ip_address' => '10.0.0.'.random_int(2, 250),
            'port' => 9100,
            'is_active' => true,
        ]);
    }

    public function test_the_till_can_charge_a_table_for_sitting(): void
    {
        $order = $this->runningOrder();
        $charge = $this->charge(1000);

        $this->actingAs($this->cashier())
            ->post(route('orders.service-charges.store', $order->id), ['service_charge_id' => $charge->id])
            ->assertSessionHasNoErrors();

        $line = $order->fresh('items')->items->firstWhere('service_charge_id', $charge->id);

        $this->assertNotNull($line, 'the charge is a line on the bill');
        $this->assertSame('خدمة طاولة', $line->product_name, 'the wording is copied, not looked up later');
        $this->assertSame(1000, $line->subtotal_cents);
        $this->assertFalse($line->prints_to_kitchen);
        $this->assertSame(2500, $order->fresh()->total_cents, 'the bill follows its lines');
    }

    public function test_the_kitchen_ticket_leaves_it_out(): void
    {
        Queue::fake();

        $this->printer();

        $order = $this->runningOrder();
        $charge = $this->charge();

        $this->actingAs($this->cashier())
            ->post(route('orders.service-charges.store', $order->id), ['service_charge_id' => $charge->id]);

        app(KitchenPrinterService::class)->queuePrintJobs($order->fresh('items'));

        Queue::assertPushed(PrintOrderTicket::class, 1);

        $job = PrintJob::query()->latest('id')->first();

        $this->assertSame(1, $job->items_count, 'only the drink is on the ticket');
    }

    /**
     * A bill whose only outstanding line is a service charge has nothing to
     * cook — and an empty ticket, or a station recorded as "never printed",
     * is worse than no ticket at all.
     */
    public function test_a_bill_of_nothing_but_charges_prints_nothing(): void
    {
        Queue::fake();

        $this->printer();

        $order = Order::create(['status' => 'confirmed', 'locale' => 'ar', 'total_cents' => 0]);
        $charge = $this->charge();

        $this->actingAs($this->cashier())
            ->post(route('orders.service-charges.store', $order->id), ['service_charge_id' => $charge->id]);

        app(KitchenPrinterService::class)->queuePrintJobs($order->fresh('items'));

        Queue::assertNotPushed(PrintOrderTicket::class);
        $this->assertSame(0, PrintJob::query()->count(), 'nothing was left unprinted, because nothing was to be cooked');
    }

    /**
     * What a table is charged is the till's decision, like a discount.
     */
    public function test_the_floor_cannot_charge_a_table(): void
    {
        $order = $this->runningOrder();
        $charge = $this->charge();

        $this->actingAs($this->waiter())
            ->post(route('orders.service-charges.store', $order->id), ['service_charge_id' => $charge->id])
            ->assertForbidden();

        $this->assertSame(1, $order->fresh('items')->items->count());
    }

    public function test_a_paid_bill_cannot_be_charged_again(): void
    {
        $order = $this->runningOrder();
        $order->update(['paid_at' => now(), 'amount_paid_cents' => 1500]);

        $this->actingAs($this->cashier())
            ->post(route('orders.service-charges.store', $order->id), ['service_charge_id' => $this->charge()->id])
            ->assertForbidden();

        $this->assertSame(1, $order->fresh('items')->items->count());
    }

    /**
     * Taking a charge off the price list must not rewrite the bills that
     * already carry it — February's takings are not March's to edit.
     */
    public function test_deleting_the_charge_leaves_settled_bills_alone(): void
    {
        $order = $this->runningOrder();
        $charge = $this->charge(1000);

        $this->actingAs($this->cashier())
            ->post(route('orders.service-charges.store', $order->id), ['service_charge_id' => $charge->id]);

        $charge->delete();

        $order->refresh()->load('items');

        $this->assertSame(2, $order->items->count());
        $this->assertSame(2500, $order->total_cents);
        $this->assertNotNull($order->items->firstWhere('product_name', 'خدمة طاولة'));
    }
}
