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
     * Only the till printer stands in. Sending the grill's ticket to the
     * drinks station puts it in front of someone who will not cook it.
     */
    public function test_a_kitchen_ticket_falls_back_to_the_cashier_printer_only(): void
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
        $this->app->instance(PrinterTransport::class, $transport);

        $service = $this->app->make(KitchenPrinterService::class);

        $this->assertFalse($service->printToStation($order, $grill, 'ماكولات'));

        $standIn = $service->printToFallback($order, $grill, 'ماكولات');

        $this->assertNotNull($standIn, 'the ticket must land somewhere');
        $this->assertSame($till->id, $standIn->id, 'it must be the till printer');
        $this->assertNotContains($drinks->name, $reached, 'another kitchen station must never receive it');
    }

    /**
     * With no till printer configured there is nowhere to send it, and the
     * job has to report failure so the queue keeps retrying.
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
     * One copy, not two: the fallback stops at the first printer that takes
     * it, so a second till printer does not produce a duplicate ticket.
     */
    public function test_the_fallback_stops_at_the_first_printer_that_accepts(): void
    {
        $grill = $this->printer('مشاوي', 'ماكولات');
        $this->printer('كاشير ١', 'cashier');
        $this->printer('كاشير ٢', 'cashier');

        $order = $this->orderWithItem('ماكولات');
        $sends = 0;

        $transport = Mockery::mock(PrinterTransport::class);
        $transport->shouldReceive('send')->andReturnUsing(function (Printer $printer) use (&$sends, $grill) {
            $sends++;

            return $printer->id !== $grill->id;
        });
        $this->app->instance(PrinterTransport::class, $transport);

        $this->app->make(KitchenPrinterService::class)->printToFallback($order, $grill, 'ماكولات');

        $this->assertSame(1, $sends, 'only the first stand-in should be sent to');
    }
}
