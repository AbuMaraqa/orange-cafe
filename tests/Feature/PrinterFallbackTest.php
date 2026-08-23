<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Printer;
use App\Services\CashierReceiptPrinterService;
use App\Services\KitchenPrinterService;
use App\Services\Printing\PrinterTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * A dead printer must not swallow the ticket. The cafe's rule: a kitchen
 * ticket falls back to the till printer and nowhere else, a till receipt
 * falls back to the kitchen, and one copy is printed — never two.
 */
class PrinterFallbackTest extends TestCase
{
    use RefreshDatabase;

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

    /**
     * An order touching several stations, for the bill's fallback — which
     * is scoped to the kitchens this order actually uses.
     *
     * @param  array<int, string>  $stations
     */
    private function orderWithItems(array $stations): Order
    {
        $order = Order::create([
            'status' => 'confirmed',
            'locale' => 'ar',
            'total_cents' => 1000,
        ]);

        foreach ($stations as $station) {
            $order->items()->create([
                'product_name' => 'طبق '.$station,
                'station' => $station,
                'unit_price_cents' => 500,
                'quantity' => 1,
                'subtotal_cents' => 500,
            ]);
        }

        return $order->fresh('items');
    }

    private function orderWithItem(string $station): Order
    {
        $order = Order::create([
            'status' => 'confirmed',
            'locale' => 'ar',
            'total_cents' => 1000,
        ]);

        $order->items()->create([
            'product_name' => 'طبق',
            'station' => $station,
            'unit_price_cents' => 1000,
            'quantity' => 1,
            'subtotal_cents' => 1000,
        ]);

        return $order->fresh('items');
    }

    /**
     * A kitchen ticket has no stand-in — not another kitchen, and not the
     * till either.
     *
     * This reverses what this test used to assert. The ticket used to fall
     * back to the cashier printer, on the reasoning that the one person who
     * could walk it over was standing there. The cafe has since decided the
     * opposite, and the reasoning is sound: paper appearing where it does
     * not belong is worse than a visible gap, because a missing ticket
     * produces a question while a misplaced one produces a wrong meal. The
     * cashier's alert now carries the message the paper used to.
     */
    public function test_a_kitchen_ticket_has_no_stand_in_printer(): void
    {
        $grill = $this->printer('مشاوي', 'ماكولات');
        $drinks = $this->printer('مشروبات', 'مشروبات');
        $till = $this->printer('كاشير', 'cashier');

        $order = $this->orderWithItem('ماكولات');
        $reached = [];

        $transport = Mockery::mock(PrinterTransport::class);
        $transport->shouldReceive('send')->andReturnUsing(function (Printer $printer) use (&$reached, $grill) {
            $reached[] = $printer->name;

            // The grill printer is dead; everything else answers.
            return $printer->id !== $grill->id;
        });
        $transport->shouldReceive('lastError')->andReturn('unreachable');
        $this->app->instance(PrinterTransport::class, $transport);

        $service = $this->app->make(KitchenPrinterService::class);

        $this->assertFalse($service->printToStation($order, $grill, 'ماكولات'));

        $this->assertNull(
            $service->printToFallback($order, $grill, 'ماكولات'),
            'a kitchen ticket must not be diverted anywhere'
        );

        $this->assertNotContains($drinks->name, $reached, 'another kitchen station must never receive it');
        $this->assertNotContains($till->name, $reached, 'nor the till');
    }

    /**
     * Still nowhere to go, and now for the simpler reason that a kitchen
     * ticket never has anywhere to go.
     */
    public function test_no_cashier_printer_means_no_fallback(): void
    {
        $grill = $this->printer('مشاوي', 'ماكولات');
        $this->printer('مشروبات', 'مشروبات');

        $order = $this->orderWithItem('ماكولات');

        $transport = Mockery::mock(PrinterTransport::class);
        $transport->shouldReceive('send')->andReturnFalse();
        $this->app->instance(PrinterTransport::class, $transport);

        $this->assertNull(
            $this->app->make(KitchenPrinterService::class)->printToFallback($order, $grill, 'ماكولات')
        );
    }

    /**
     * The bill does have somewhere to go: a kitchen already working on this
     * order, so the paper lands beside the food it belongs to.
     */
    public function test_a_cashier_receipt_falls_back_to_a_kitchen_printer(): void
    {
        $till = $this->printer('كاشير', 'cashier');
        $grill = $this->printer('مشاوي', 'ماكولات');

        $order = $this->orderWithItem('ماكولات');

        $transport = Mockery::mock(PrinterTransport::class);
        $transport->shouldReceive('send')->andReturnUsing(fn (Printer $printer) => $printer->id !== $till->id);
        $this->app->instance(PrinterTransport::class, $transport);

        $standIn = $this->app->make(CashierReceiptPrinterService::class)->printToFallback($order, $till);

        $this->assertNotNull($standIn);
        $this->assertSame($grill->id, $standIn->id);
    }

    /**
     * One copy, not two: the bill's fallback stops at the first kitchen
     * that takes it, so an order spanning three stations does not produce
     * three bills.
     */
    public function test_the_fallback_stops_at_the_first_printer_that_accepts(): void
    {
        $till = $this->printer('كاشير', 'cashier');
        $this->printer('مشاوي', 'ماكولات');
        $this->printer('مشروبات', 'مشروبات');

        $order = $this->orderWithItems(['ماكولات', 'مشروبات']);
        $sends = 0;

        $transport = Mockery::mock(PrinterTransport::class);
        $transport->shouldReceive('send')->andReturnUsing(function (Printer $printer) use (&$sends, $till) {
            $sends++;

            return $printer->id !== $till->id;
        });
        $this->app->instance(PrinterTransport::class, $transport);

        $this->app->make(CashierReceiptPrinterService::class)->printToFallback($order, $till);

        $this->assertSame(1, $sends, 'only the first stand-in should be sent to');
    }
}
