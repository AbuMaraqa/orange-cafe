<?php

namespace App\Services\Printing;

use App\Models\Printer;
use RuntimeException;

/**
 * Prints through the Windows print spooler instead of talking to the printer
 * itself.
 *
 * This exists because direct USB printing is impossible in the packaged app
 * and always will be: UsbPrinterTransport drives libusb through PHP's FFI
 * extension, and the static PHP build NativePHP ships has no ext-ffi and no
 * ext/ directory to add one to. A cafe whose till printer is a USB model was
 * simply stuck — the app told them to buy a network printer.
 *
 * Windows already knows how to talk to that printer: it has the driver, and
 * the spooler queues for it. Sharing the printer gives it a UNC path, and
 * writing raw bytes to that path hands the job straight to the spooler,
 * which passes them through to the device untouched — exactly what a thermal
 * printer needs.
 *
 * Deliberately plain filesystem I/O rather than escpos-php's own
 * WindowsPrintConnector, which shells out to `copy` through proc_open. One
 * less thing to be disabled or sandboxed in a bundled runtime, no temp file
 * to leave behind, and the failure is a normal PHP error instead of an exit
 * code from a shell.
 *
 * The printer must be shared in Windows (Printer properties → Sharing →
 * Share this printer) and the share name entered in the app. A share name
 * with spaces works, but is worth avoiding.
 */
class WindowsPrinterTransport
{
    /**
     * Whether this transport can run at all — Windows only, since a UNC
     * printer path means nothing anywhere else.
     */
    public static function supported(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    /**
     * @throws RuntimeException when the spooler would not take the job
     */
    public function send(Printer $printer, string $data): void
    {
        if (! self::supported()) {
            throw new RuntimeException('Windows spooler printing is only available on Windows.');
        }

        $share = trim((string) $printer->windows_share);

        if ($share === '') {
            throw new RuntimeException("Printer [{$printer->name}] has no Windows share name configured.");
        }

        $device = $this->devicePath($share, $printer->ip_address);

        // The write is the print job: the spooler accepts the bytes and
        // returns, so a successful write means "queued", not "on paper". A
        // printer that is out of paper or switched off still queues.
        $written = @file_put_contents($device, $data);

        if ($written === false) {
            throw new RuntimeException(
                "Could not reach the Windows printer share [{$device}]. ".
                'Check that the printer is shared in Windows and that the share name matches.'
            );
        }
    }

    /**
     * A share on this machine unless a host was given — the normal case is
     * the printer plugged into the machine running the app.
     */
    public function devicePath(string $share, ?string $host = null): string
    {
        $host = trim((string) $host);

        if ($host === '') {
            $host = 'localhost';
        }

        return '\\\\'.$host.'\\'.ltrim($share, '\\/');
    }

    /**
     * Whether the share is reachable, without printing anything.
     *
     * A probe must never emit paper: a status sweep that prints a blank
     * receipt every minute would empty a roll overnight. Opening the path
     * for writing and closing it immediately queues nothing.
     */
    public function reachable(Printer $printer): bool
    {
        if (! self::supported() || blank($printer->windows_share)) {
            return false;
        }

        $handle = @fopen($this->devicePath($printer->windows_share, $printer->ip_address), 'wb');

        if ($handle === false) {
            return false;
        }

        fclose($handle);

        return true;
    }
}
