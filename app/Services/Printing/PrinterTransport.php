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
    public function __construct(
        private UsbPrinterTransport $usb,
        private WindowsPrinterTransport $windows,
    ) {}

    /**
     * Why the last send or probe failed.
     *
     * Held on the object because send() answers a bool, and the callers now
     * need the reason — it goes on the print job and in front of the
     * cashier. Widening every return type for something only the failure
     * path reads would touch every call site.
     */
    private string $lastError = '';

    public function lastError(): string
    {
        return $this->lastError;
    }

    /**
     * @param  \DateTimeInterface|null  $printedOrderAt  when the order being printed was placed;
     *         lets a recovery noticed by this send spare that order — see
     *         PrinterHealth::markReachable.
     */
    public function send(Printer $printer, string $data, ?\DateTimeInterface $printedOrderAt = null): bool
    {
        $this->lastError = '';

        try {
            match (true) {
                $printer->isWindowsSpooler() => $this->windows->send($printer, $data),
                $printer->isUsb() => $this->sendUsb($printer, $data),
                default => $this->sendNetwork($printer, $data),
            };
            // The order this send belongs to, so a recovery noticed here
            // cannot hold the rest of that same order — see
            // PrinterHealth::markReachable.
            $printer->recordStatus(reachable: true, printedOrderAt: $printedOrderAt);

            return true;
        } catch (Throwable $e) {
            Log::warning("Printer send failed: {$printer->name} ({$printer->connection_type}) — {$e->getMessage()}");
            // The reason is kept on the printer, not only in the log:
            // whoever fixes it reads the printers screen, and "offline"
            // alone cannot tell a refused socket from a wrong IP from a
            // Windows share that no longer exists.
            $printer->recordStatus(reachable: false, error: $e->getMessage());
            $this->lastError = $e->getMessage();

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
        $this->lastError = '';

        try {
            match (true) {
                // A spooler printer has no address to open a socket to, so the
                // network probe would fail it every time — the scheduled
                // sweep would paint a perfectly working till printer red and
                // "test connection" would never pass. Asking Windows whether
                // the share can be opened is the equivalent question, and it
                // queues nothing.
                $printer->isWindowsSpooler() => $this->probeWindows($printer),
                $printer->isUsb() => $this->usb->probe($printer->usb_vendor_id, $printer->usb_product_id),
                default => $this->probeNetwork($printer),
            };
            $printer->recordStatus(reachable: true);

            return true;
        } catch (Throwable $e) {
            $printer->recordStatus(reachable: false, error: $e->getMessage());
            $this->lastError = $e->getMessage();

            throw $e;
        }
    }

    private function probeNetwork(Printer $printer): void
    {
        // Same timeout as the send path: the sweep walks every printer,
        // and 3s each turned a handful of dead printers into a sweep that
        // outlived its own one-minute schedule.
        $socket = @fsockopen($printer->ip_address, $printer->port, $errno, $errstr, self::CONNECT_TIMEOUT_SECONDS);

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
    // just needs a moment. So a couple of fast in-process retries happen
    // here, before the job-level retry gets involved.
    //
    // Two attempts, not four, and a 1.2s connect timeout, not 3s. The old
    // numbers meant a printer that was simply switched off held the worker
    // for 4 × (3s + 0.7s) ≈ 15 seconds — per job-level attempt — and the
    // queue is serial, so every ticket behind it waited too. One dead
    // printer could stall the whole pass. These numbers still cover the
    // hiccup they exist for (it clears in well under a second) while
    // failing a genuinely absent printer in about 2.5s instead of 15.
    //
    // A printer that is actually there answers a LAN connect in
    // milliseconds; 1.2s is already many times what it needs.
    private const CONNECT_ATTEMPTS = 2;

    private const CONNECT_TIMEOUT_SECONDS = 1.2;

    private const CONNECT_RETRY_DELAY_MICROSECONDS = 700_000;

    private function sendNetwork(Printer $printer, string $data): void
    {
        $socket = false;
        $errstr = '';

        for ($attempt = 1; $attempt <= self::CONNECT_ATTEMPTS; $attempt++) {
            $socket = @fsockopen($printer->ip_address, $printer->port, $errno, $errstr, self::CONNECT_TIMEOUT_SECONDS);

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

    /**
     * @throws RuntimeException when Windows will not accept the share
     */
    private function probeWindows(Printer $printer): void
    {
        if (! $this->windows->reachable($printer)) {
            throw new RuntimeException(
                "Windows will not open the printer share [{$printer->windows_share}]. ".
                'Check that the printer is shared and the share name matches.'
            );
        }
    }
}
