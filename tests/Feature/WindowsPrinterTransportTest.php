<?php

namespace Tests\Feature;

use App\Models\Printer;
use App\Services\Printing\PrinterTransport;
use App\Services\Printing\WindowsPrinterTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Printing a USB till printer through the Windows spooler.
 *
 * Direct USB is impossible in the packaged app — the bundled PHP has no FFI
 * extension for libusb and no way to add one — so a cafe with a USB till
 * printer had no working option at all. Windows already has the driver;
 * sharing the printer gives it a path that raw ESC/POS bytes can be written
 * to.
 */
class WindowsPrinterTransportTest extends TestCase
{
    use RefreshDatabase;

    private function printer(array $attributes = []): Printer
    {
        return Printer::create(array_merge([
            'name' => 'طابعة الكاشير',
            'station' => 'cashier',
            'connection_type' => 'windows',
            'windows_share' => 'CASHIER',
            'is_active' => true,
        ], $attributes));
    }

    public function test_a_local_share_resolves_to_this_machine(): void
    {
        $transport = app(WindowsPrinterTransport::class);

        $this->assertSame('\\\\localhost\\CASHIER', $transport->devicePath('CASHIER'));
    }

    public function test_a_share_on_another_pc_uses_that_host(): void
    {
        $transport = app(WindowsPrinterTransport::class);

        $this->assertSame('\\\\POS-PC\\CASHIER', $transport->devicePath('CASHIER', 'POS-PC'));
    }

    public function test_a_printer_with_no_share_name_is_refused(): void
    {
        $printer = $this->printer(['windows_share' => null]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/share name/i');

        app(WindowsPrinterTransport::class)->send($printer, 'bytes');
    }

    public function test_the_model_reports_the_right_connection_kind(): void
    {
        $printer = $this->printer();

        $this->assertTrue($printer->isWindowsSpooler());
        $this->assertFalse($printer->isUsb());
    }

    /**
     * The routing is the point: a spooler printer must not be handed to the
     * USB path (which cannot run here) or the network path (which has no
     * address to dial).
     */
    public function test_the_transport_routes_a_spooler_printer_to_windows(): void
    {
        $printer = $this->printer();

        $windows = Mockery::mock(WindowsPrinterTransport::class);
        $windows->shouldReceive('send')->once()->with(Mockery::type(Printer::class), 'ESC/POS');
        $this->app->instance(WindowsPrinterTransport::class, $windows);

        $this->assertTrue(app(PrinterTransport::class)->send($printer, 'ESC/POS'));
    }

    /**
     * A probe must never emit paper — a sweep that printed a blank receipt
     * every minute would empty the roll overnight.
     */
    public function test_probing_asks_windows_instead_of_opening_a_socket(): void
    {
        $printer = $this->printer();

        $windows = Mockery::mock(WindowsPrinterTransport::class);
        $windows->shouldReceive('reachable')->once()->andReturnTrue();
        $windows->shouldNotReceive('send');
        $this->app->instance(WindowsPrinterTransport::class, $windows);

        $this->assertTrue(app(PrinterTransport::class)->probe($printer));
        $this->assertSame('online', $printer->fresh()->status);
    }

    public function test_an_unreachable_share_is_recorded_offline(): void
    {
        $printer = $this->printer();

        $windows = Mockery::mock(WindowsPrinterTransport::class);
        // Twice: a printer is not called down on a single failure any more,
        // because one refused check is a printer that is busy rather than
        // one that is off — see PrinterBlipDoesNotHoldTicketsTest.
        $windows->shouldReceive('reachable')->twice()->andReturnFalse();
        $this->app->instance(WindowsPrinterTransport::class, $windows);

        foreach (range(1, 2) as $attempt) {
            try {
                app(PrinterTransport::class)->probe($printer->fresh());
                $this->fail('an unreachable share must raise');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('CASHIER', $e->getMessage());
            }
        }

        $this->assertSame('offline', $printer->fresh()->status);
    }
}
