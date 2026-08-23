<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Services\Printing\PrinterHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A printer that never stopped working must not hold the kitchen's tickets.
 *
 * `recovered_at` blocks every ticket for an order placed before it. That is
 * right after a real outage — a printer reconnecting at 13:00 must not spit
 * out the orders it missed since 12:00 — and ruinous for a blip.
 *
 * These printers accept one TCP connection at a time, and the status check
 * ran every minute. A check landing while a ticket was printing was refused
 * by a printer that was working perfectly; that counted as "down", the next
 * check counted as "recovered", and the stamp held every ticket in the
 * building. Silently: a held job sits at PENDING, and PENDING was excluded
 * from the alert that exists to say something did not print.
 *
 * So the cafe saw printers marked "online", a green "sent to the printer"
 * message, an empty queue, no alert — and no paper. Every screen agreed
 * that everything was fine.
 */
class PrinterBlipDoesNotHoldTicketsTest extends TestCase
{
    use RefreshDatabase;

    private function printer(): Printer
    {
        return Printer::create([
            'name' => 'المطبخ',
            'station' => 'kitchen',
            'connection_type' => 'network',
            'ip_address' => '10.0.0.5',
            'port' => 9100,
            'is_active' => true,
        ]);
    }

    private function health(): PrinterHealth
    {
        return app(PrinterHealth::class);
    }

    // -----------------------------------------------------------------
    // One failed check is not an outage
    // -----------------------------------------------------------------

    public function test_a_single_failed_check_does_not_mark_the_printer_down(): void
    {
        $printer = $this->printer();
        $printer->forceFill(['status' => PrinterHealth::ONLINE])->save();

        $this->health()->markUnreachable($printer, 'busy');

        $this->assertSame(PrinterHealth::ONLINE, $printer->fresh()->status, 'A printer mid-ticket is not a printer that is off.');
        $this->assertSame(1, $printer->fresh()->consecutive_failures, 'The failure is still counted.');
    }

    public function test_two_failed_checks_do_mark_it_down(): void
    {
        $printer = $this->printer();
        $printer->forceFill(['status' => PrinterHealth::ONLINE])->save();

        $this->health()->markUnreachable($printer, 'no route');
        $this->health()->markUnreachable($printer->fresh(), 'no route');

        $this->assertSame(PrinterHealth::OFFLINE, $printer->fresh()->status);
    }

    // -----------------------------------------------------------------
    // Only a real outage stamps a recovery
    // -----------------------------------------------------------------

    public function test_a_brief_blip_does_not_stamp_a_recovery(): void
    {
        $printer = $this->printer();
        $printer->forceFill([
            'status' => PrinterHealth::OFFLINE,
            'offline_since' => now()->subSeconds(30),
        ])->save();

        $this->health()->markReachable($printer);

        $this->assertNull(
            $printer->fresh()->recovered_at,
            'Thirty seconds down is a blip; nothing was missed, so nothing should be held.'
        );
    }

    public function test_a_real_outage_still_stamps_a_recovery(): void
    {
        $printer = $this->printer();
        $printer->forceFill([
            'status' => PrinterHealth::OFFLINE,
            'offline_since' => now()->subMinutes(30),
        ])->save();

        $this->health()->markReachable($printer);

        $this->assertNotNull(
            $printer->fresh()->recovered_at,
            'Half an hour off really did miss orders — that backlog still waits for a person.'
        );
    }

    /**
     * The first check on a fresh install used to count as a recovery,
     * stamping the moment the app started and holding every ticket for
     * every order already in the database.
     */
    public function test_a_printer_that_was_never_recorded_down_never_stamps(): void
    {
        $printer = $this->printer();
        $printer->forceFill(['status' => PrinterHealth::OFFLINE, 'offline_since' => null])->save();

        $this->health()->markReachable($printer);

        $this->assertNull($printer->fresh()->recovered_at);
    }

    public function test_a_ticket_placed_before_a_blip_still_prints(): void
    {
        $printer = $this->printer();
        $order = Order::create(['status' => 'confirmed', 'locale' => 'ar', 'total_cents' => 1000]);

        $printer->forceFill([
            'status' => PrinterHealth::OFFLINE,
            'offline_since' => now()->subSeconds(20),
        ])->save();

        $this->health()->markReachable($printer);

        $this->assertTrue(
            $this->health()->mayAutoPrint($printer->fresh(), $order->created_at),
            'The whole point: a printer that blipped must not hold the ticket in front of it.'
        );
    }

    // -----------------------------------------------------------------
    // And a held ticket has to be visible
    // -----------------------------------------------------------------

    public function test_a_held_ticket_shows_up_as_needing_attention(): void
    {
        $printer = $this->printer();
        $order = Order::create(['status' => 'confirmed', 'locale' => 'ar', 'total_cents' => 1000]);

        PrintJob::create([
            'reference' => 'P-HELD',
            'order_id' => $order->id,
            'printer_id' => $printer->id,
            'station' => 'kitchen',
            'printer_name' => $printer->name,
            'kind' => PrintJob::KIND_KITCHEN,
            'status' => PrintJob::PENDING,
            'last_error' => __('printing.held_after_recovery'),
            'idempotency_key' => 'kitchen:held:1',
        ]);

        $this->assertSame(
            1,
            PrintJob::query()->needsAttention()->count(),
            'Held is the one state that needs a person, and it was the one state nothing reported.'
        );
    }

    public function test_a_freshly_queued_ticket_does_not_raise_the_alarm(): void
    {
        $printer = $this->printer();
        $order = Order::create(['status' => 'confirmed', 'locale' => 'ar', 'total_cents' => 1000]);

        PrintJob::create([
            'reference' => 'P-NEW',
            'order_id' => $order->id,
            'printer_id' => $printer->id,
            'station' => 'kitchen',
            'printer_name' => $printer->name,
            'kind' => PrintJob::KIND_KITCHEN,
            'status' => PrintJob::PENDING,
            'last_error' => null,
            'idempotency_key' => 'kitchen:new:1',
        ]);

        $this->assertSame(
            0,
            PrintJob::query()->needsAttention()->count(),
            'A job queued a second ago is not a problem; its lack of an error is what says so.'
        );
    }
}
