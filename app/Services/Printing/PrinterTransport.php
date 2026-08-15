<?php

namespace App\Services\Printing;

use App\Models\Printer;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Delivers already-built ESC/POS bytes to a Printer, over whichever
 * transport it's configured for (network socket or direct USB) — the
 * one place that knows how to physically reach a printer, so callers
 * (KitchenPrinterService, CashierReceiptPrinterService) only deal with
 * building ticket content.
 */
class PrinterTransport
{
    public function __construct(private UsbPrinterTransport $usb)
    {
    }

    public function send(Printer $printer, string $data): bool
    {
        try {
            $printer->isUsb() ? $this->sendUsb($printer, $data) : $this->sendNetwork($printer, $data);
            $printer->recordStatus(reachable: true);

            return true;
        } catch (Throwable $e) {
            Log::warning("Printer send failed: {$printer->name} ({$printer->connection_type}) — {$e->getMessage()}");
            $printer->recordStatus(reachable: false);

            return false;
        }
    }

    /**
     * Connectivity-only check (no data sent) — used by the admin "Test
     * connection" button and the scheduled status sweep, both of which
     * want to know if a printer is reachable without printing anything.
     */
    public function probe(Printer $printer): bool
    {
        try {
            $printer->isUsb() ? $this->usb->probe($printer->usb_vendor_id, $printer->usb_product_id) : $this->probeNetwork($printer);
            $printer->recordStatus(reachable: true);

            return true;
        } catch (Throwable $e) {
            $printer->recordStatus(reachable: false);

            throw $e;
        }
    }

    private function probeNetwork(Printer $printer): void
    {
        $socket = @fsockopen($printer->ip_address, $printer->port, $errno, $errstr, 3);

        if (! $socket) {
            throw new RuntimeException($errstr ?: "unreachable ({$printer->ip_address}:{$printer->port})");
        }

        fclose($socket);
    }

    private function sendUsb(Printer $printer, string $data): void
    {
        $this->usb->send($printer->usb_vendor_id, $printer->usb_product_id, $data);
    }

    // Some thermal printer firmware (this restaurant's شيشة printer,
    // confirmed) briefly refuses new TCP connections right after closing
    // a previous one — it's still physically plugged in and working, it
    // just needs a moment. The job-level retry (30s backoff) is the
    // right safety net for a printer that's genuinely off/unplugged, but
    // it's far too slow for a hiccup that clears up in under a second —
    // so a handful of fast in-process retries happen here first.
    private const CONNECT_ATTEMPTS = 4;

    private const CONNECT_RETRY_DELAY_MICROSECONDS = 700_000;

    private function sendNetwork(Printer $printer, string $data): void
    {
        $socket = false;
        $errstr = '';

        for ($attempt = 1; $attempt <= self::CONNECT_ATTEMPTS; $attempt++) {
            $socket = @fsockopen($printer->ip_address, $printer->port, $errno, $errstr, 3);

            if ($socket) {
                break;
            }

            if ($attempt < self::CONNECT_ATTEMPTS) {
                usleep(self::CONNECT_RETRY_DELAY_MICROSECONDS);
            }
        }

        if (! $socket) {
            throw new RuntimeException("unreachable ({$printer->ip_address}:{$printer->port}) after ".self::CONNECT_ATTEMPTS." attempts — {$errstr}");
        }

        try {
            $this->writeAll($socket, $data);
        } finally {
            fclose($socket);
        }
    }

    /**
     * fwrite() on a socket stream is not guaranteed to send the whole
     * string in one call — for a ~40KB raster image it silently wrote
     * only part of the buffer on real hardware, corrupting the print
     * (blank gaps where image data got cut off). This loops until every
     * byte is confirmed sent.
     */
    private function writeAll($socket, string $data): void
    {
        $length = strlen($data);
        $written = 0;

        while ($written < $length) {
            $chunk = fwrite($socket, substr($data, $written));

            if ($chunk === false || $chunk === 0) {
                throw new RuntimeException("socket write stalled after {$written}/{$length} bytes");
            }

            $written += $chunk;
        }
    }
}
