<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\ServiceCharge;
use App\Settings\RestaurantSettings;
use Database\Seeders\LanguageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The other half of ServiceChargeNeverPrintsTest.
 *
 * That one proves a charge stays off the kitchen ticket, in six ways, and it
 * is the half that had all the attention because it is the half that could
 * cost a service. Nothing anywhere asserted the part the café actually sells
 * on: that a table held past two hours, or a birthday cake carried out by a
 * member of staff, reaches the paper the customer is handed.
 *
 * Both directions matter and they fail differently. A charge on the kitchen
 * ticket confuses a chef; a charge missing from the till receipt is money the
 * customer was asked for and cannot see, which is the one a customer argues
 * about at the counter.
 *
 * The template is rendered directly rather than driven through
 * CashierReceiptPrinterService, because everything between the two is the
 * rasteriser: mPDF, then pdftoppm, then ESC/POS. Those need poppler on the
 * machine running the suite and they answer a different question — whether
 * the bytes reach a printer, not whether the line is on the bill.
 */
class ServiceChargePrintsOnTheBillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LanguageSeeder::class);
    }

    /**
     * The receipt exactly as CashierReceiptPrinterService::buildReceipt()
     * composes it, minus the logo — which is a filesystem path and has
     * nothing to do with what is on the bill.
     */
    private function receipt(Order $order): string
    {
        return view('pdf.invoice-thermal', [
            'notice' => null,
            'order' => $order->fresh(['items.modifiers', 'table']),
            'branding' => app(RestaurantSettings::class),
            'logoPath' => null,
            'currencySymbol' => '₪',
            'direction' => 'rtl',
            'labels' => __('pdf'),
        ])->render();
    }

    private function orderWithACharge(string $name, int $amountCents): Order
    {
        $order = Order::create(['status' => 'confirmed', 'locale' => 'ar', 'total_cents' => 0]);

        $product = Product::create(['slug' => 'tea-'.fake()->unique()->numberBetween(1, 99999), 'price_cents' => 500, 'is_active' => true]);
        $product->fill(['ar' => ['name' => 'شاي']])->save();

        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => 'شاي',
            'prints_to_kitchen' => true,
            'unit_price_cents' => 500,
            'quantity' => 1,
            'subtotal_cents' => 500,
        ]);

        $charge = ServiceCharge::create(['amount_cents' => $amountCents, 'is_active' => true]);
        $charge->fill(['ar' => ['name' => $name]])->save();

        $order->items()->create([
            'service_charge_id' => $charge->id,
            'product_name' => $name,
            'prints_to_kitchen' => false,
            'station' => null,
            'unit_price_cents' => $amountCents,
            'quantity' => 1,
            'subtotal_cents' => $amountCents,
        ]);

        $order->recalculateTotal();

        return $order;
    }

    public function test_a_table_charge_is_on_the_receipt_the_customer_is_handed(): void
    {
        // The example the model was written for: a seat held past two hours.
        $receipt = $this->receipt($this->orderWithACharge('خدمة طاولة', 2000));

        $this->assertStringContainsString('خدمة طاولة', $receipt);
        $this->assertStringContainsString('20.00', $receipt);
    }

    public function test_something_sold_off_the_menu_is_on_it_too(): void
    {
        // A birthday cake: not on the menu, never browsable by a customer,
        // carried to the table because somebody asked at the counter. It
        // reaches the bill the same way a table charge does.
        $receipt = $this->receipt($this->orderWithACharge('كيكة عيد ميلاد', 5000));

        $this->assertStringContainsString('كيكة عيد ميلاد', $receipt);
        $this->assertStringContainsString('50.00', $receipt);
    }

    public function test_the_dish_beside_it_is_still_there(): void
    {
        // A charge must be an addition to the bill, never a replacement for
        // it — the loop that prints the lines is shared, so a mistake there
        // would take the food with it.
        $receipt = $this->receipt($this->orderWithACharge('خدمة طاولة', 2000));

        $this->assertStringContainsString('شاي', $receipt);
        $this->assertStringContainsString('5.00', $receipt);
    }

    public function test_the_total_the_customer_pays_includes_it(): void
    {
        $order = $this->orderWithACharge('خدمة طاولة', 2000);

        // 5.00 tea + 20.00 for the table.
        $this->assertSame(2500, $order->fresh()->total_cents);
        $this->assertStringContainsString('25.00', $this->receipt($order));
    }
}
