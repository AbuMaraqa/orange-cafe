<?php

namespace App\Services\Printing;

use FFI;
use RuntimeException;

/**
 * Sends raw ESC/POS bytes to a USB-connected thermal printer that uses a
 * vendor-specific USB interface class (common on cheap POS receipt
 * printers, including the cashier's) rather than the standard USB
 * Printer Class — so neither the kernel's usblp driver nor CUPS can
 * address it. This talks to it directly via libusb's bulk transfer API
 * through PHP's FFI extension, which is only available from CLI SAPI —
 * fine here since printing always runs inside a queued job.
 *
 * Requires the printer's USB device node to be writable by the running
 * process — see the udev rule granting the 'plugdev' group access
 * (99-cashier-printer.rules).
 */
class UsbPrinterTransport
{
    private const CHUNK_SIZE = 16384;

    private const TIMEOUT_MS = 5000;

    private const INTERFACE_NUMBER = 0;

    private const ENDPOINT_OUT = 0x01;

    private static ?FFI $ffi = null;

    /**
     * Opens and immediately releases the device — confirms it's present
     * and its device node is writable by this process, without printing
     * anything. Used by the admin "Test connection" button.
     */
    public function probe(string $vendorIdHex, string $productIdHex): void
    {
        $this->send($vendorIdHex, $productIdHex, '');
    }

    public function send(string $vendorIdHex, string $productIdHex, string $data): void
    {
        $ffi = $this->ffi();

        $ctxPtr = $ffi->new('libusb_context *');

        if ($ffi->libusb_init(FFI::addr($ctxPtr)) !== 0) {
            throw new RuntimeException('libusb_init failed');
        }

        try {
            $handle = $ffi->libusb_open_device_with_vid_pid($ctxPtr, hexdec($vendorIdHex), hexdec($productIdHex));

            if ($handle === null || FFI::isNull($handle)) {
                throw new RuntimeException(
                    "USB printer {$vendorIdHex}:{$productIdHex} not found, or its device node isn't writable by this process — check the udev rule and that the printer is plugged in."
                );
            }

            try {
                $claimResult = $ffi->libusb_claim_interface($handle, self::INTERFACE_NUMBER);

                if ($claimResult !== 0) {
                    throw new RuntimeException("libusb_claim_interface failed (code {$claimResult}) — permission denied, or another process is using the printer.");
                }

                try {
                    $this->bulkWriteAll($ffi, $handle, $data);
                } finally {
                    $ffi->libusb_release_interface($handle, self::INTERFACE_NUMBER);
                }
            } finally {
                $ffi->libusb_close($handle);
            }
        } finally {
            $ffi->libusb_exit($ctxPtr);
        }
    }

    private function bulkWriteAll(FFI $ffi, $handle, string $data): void
    {
        $length = strlen($data);
        $offset = 0;

        while ($offset < $length) {
            $chunk = substr($data, $offset, self::CHUNK_SIZE);
            $chunkLength = strlen($chunk);

            $buffer = $ffi->new("unsigned char[{$chunkLength}]");
            FFI::memcpy($buffer, $chunk, $chunkLength);

            $transferred = $ffi->new('int');
            $result = $ffi->libusb_bulk_transfer($handle, self::ENDPOINT_OUT, $buffer, $chunkLength, FFI::addr($transferred), self::TIMEOUT_MS);

            if ($result !== 0) {
                throw new RuntimeException("libusb_bulk_transfer failed (code {$result}) after {$offset}/{$length} bytes");
            }

            $offset += $transferred->cdata;
        }
    }

    private function ffi(): FFI
    {
        return self::$ffi ??= FFI::cdef(<<<'CDEF'
            typedef struct libusb_context libusb_context;
            typedef struct libusb_device_handle libusb_device_handle;

            int libusb_init(libusb_context **ctx);
            void libusb_exit(libusb_context *ctx);
            libusb_device_handle *libusb_open_device_with_vid_pid(libusb_context *ctx, unsigned short vendor_id, unsigned short product_id);
            int libusb_claim_interface(libusb_device_handle *dev_handle, int interface_number);
            int libusb_release_interface(libusb_device_handle *dev_handle, int interface_number);
            void libusb_close(libusb_device_handle *dev_handle);
            int libusb_bulk_transfer(libusb_device_handle *dev_handle, unsigned char endpoint, unsigned char *data, int length, int *actual_length, unsigned int timeout);
            CDEF, config('services.libusb.path'));
    }
}
