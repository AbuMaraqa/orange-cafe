<?php

namespace Tests\Feature;

use App\Console\Commands\CheckPrinterStatuses;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Printer;
use App\Services\Printing\HostPing;
use App\Services\Printing\PrinterHealth;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\LanguageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Silence from a printer is not evidence that it is dead.
 *
 * The idle status sweep asks by ICMP, deliberately: opening and abandoning
 * the print port makes a thermal printer beep, once a minute, all day. What
 * it did with the answer was the mistake — it recorded "no reply" as "this
 * printer is offline", and plenty of thermal printers never answer ICMP at
 * all. On one site all three network printers ignored ping while accepting
 * work on 9100 the entire time.
 *
 * The damage was not a wrong colour on a screen. A printer believed down and
 * then printing successfully reads as recovery from an outage, and
 * PrinterHealth holds every ticket for an order placed before that instant
 * so a returning printer cannot dump an hour of stale food on the pass. With
 * an outage that never happened, that protection became the fault: tickets
 * stopped printing, silently, with a queue full of jobs sitting at pending.
 */
class PrinterStaysUpWhenItIgnoresPingTest extends TestCase
{
    use RefreshDatabase;

    private function networkPrinter(string $status = PrinterHealth::ONLINE): Printer
    {
        $printer = Printer::create([
            'name' => 'المأكولات',
            'station' => 'kitchen',
            'connection_type' => 'network',
            'ip_address' => '192.168.1.10',
            'port' => 9100,
            'is_active' => true,
        ]);

        // status is not fillable — it belongs to PrinterHealth, not to
        // whoever is creating the row.
        $printer->forceFill(['status' => $status])->save();

        return $printer;
    }

    /** A ping that answers nothing at all. */
    private function silenceTheNetwork(): void
    {
        $this->instance(HostPing::class, new class extends HostPing
        {
            public function reachable(?string $host): bool
            {
                return false;
            }
        });
    }

    public function test_a_printer_that_ignores_ping_is_not_marked_down(): void
    {
        $printer = $this->networkPrinter();
        $this->silenceTheNetwork();

        $this->artisan(CheckPrinterStatuses::class);

        $printer->refresh();

        $this->assertSame(PrinterHealth::ONLINE, $printer->status);
        $this->assertSame(0, $printer->consecutive_failures);
        $this->assertNull($printer->offline_since);
    }

    /**
     * The sweep may still say yes. A reply proves the device is powered on
     * and on the network, and a printer that had been marked down by a real
     * failed send should go green again without waiting for the next order.
     */
    public function test_a_ping_that_answers_still_brings_a_printer_back(): void
    {
        $printer = $this->networkPrinter(PrinterHealth::OFFLINE);
        $printer->forceFill(['offline_since' => now()->subMinutes(30)])->save();

        $this->instance(HostPing::class, new class extends HostPing
        {
            public function reachable(?string $host): bool
            {
                return true;
            }
        });

        $this->artisan(CheckPrinterStatuses::class);

        $this->assertSame(PrinterHealth::ONLINE, $printer->refresh()->status);
    }

    /**
     * A recovery noticed while printing must not hold the order that proved
     * the printer works.
     *
     * An order with items at two stations queues two tickets. The first
     * printed, which marked the printer up after a genuine outage — and
     * stamping that recovery at `now()` put the order, created seconds
     * earlier, on the wrong side of the line. Half a table's food reached
     * the pass and the other half was filed as backlog nobody was told
     * about, which is precisely the split this stamp exists to prevent.
     */
    public function test_a_recovery_does_not_hold_the_order_that_caused_it(): void
    {
        $this->seed(LanguageSeeder::class);
        $this->seed(CatalogSeeder::class);

        $printer = $this->networkPrinter(PrinterHealth::OFFLINE);
        $printer->forceFill(['offline_since' => now()->subHour()])->save();

        $item = MenuItem::with('product')->firstOrFail();
        $order = Order::create(['status' => 'confirmed', 'locale' => 'ar', 'total_cents' => 0]);

        $health = app(PrinterHealth::class);

        // The send that succeeds belongs to this order.
        $printer->recordStatus(reachable: true, printedOrderAt: $order->created_at);
        $printer->refresh();

        $this->assertNotNull($printer->recovered_at, 'a real outage still stamps a recovery');
        $this->assertTrue(
            $health->mayAutoPrint($printer, $order->created_at),
            'the order that brought the printer back must still be allowed to finish printing',
        );
    }

    /**
     * And the guarantee it was protecting still holds: the hour of tickets
     * missed during the outage waits for a person.
     */
    public function test_the_backlog_from_a_real_outage_is_still_held(): void
    {
        $printer = $this->networkPrinter(PrinterHealth::OFFLINE);
        $printer->forceFill(['offline_since' => now()->subHour()])->save();

        $duringTheOutage = now()->subMinutes(30);
        $theOrderThatRecoveredIt = now();

        $printer->recordStatus(reachable: true, printedOrderAt: $theOrderThatRecoveredIt);

        $this->assertFalse(
            app(PrinterHealth::class)->mayAutoPrint($printer->refresh(), $duringTheOutage),
            'food ordered during the outage must not print itself an hour late',
        );
    }
}
