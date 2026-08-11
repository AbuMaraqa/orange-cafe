<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Printer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Routes an order's items to the kitchen printer(s) responsible for their
 * station and sends an ESC/POS ticket over a raw TCP socket — the standard
 * protocol spoken by network kitchen/receipt printers (Epson, Star, etc.
 * listening on port 9100). Nothing here is simulated: against a real
 * network printer at the configured IP this prints an actual ticket. There
 * is no physical printer in this environment to verify output against, so
 * every printer round-trip is wrapped and logged rather than allowed to
 * fail the request.
 */
class KitchenPrinterService
{
    private const ESC = "\x1B";
    private const GS = "\x1D";

    public function printOrder(Order $order): void
    {
        $order->loadMissing('items.modifiers', 'table');

        $itemsByStation = $order->items->groupBy(fn (OrderItem $item) => $item->station ?? 'default');

        foreach ($itemsByStation as $station => $items) {
            $printers = Printer::query()->where('station', $station)->where('is_active', true)->get();

            foreach ($printers as $printer) {
                $this->sendToPrinter($printer, $order, $items);
            }
        }
    }

    protected function sendToPrinter(Printer $printer, Order $order, Collection $items): void
    {
        $socket = @fsockopen($printer->ip_address, $printer->port, $errno, $errstr, 3);

        if (! $socket) {
            Log::warning("Kitchen printer unreachable: {$printer->name} ({$printer->ip_address}:{$printer->port}) — {$errstr}");

            return;
        }

        try {
            fwrite($socket, $this->buildTicket($order, $items));
        } finally {
            fclose($socket);
        }
    }

    protected function buildTicket(Order $order, Collection $items): string
    {
        $boldOn = self::ESC.'E'."\x01";
        $boldOff = self::ESC.'E'."\x00";
        $doubleHeight = self::GS.'!'."\x11";
        $normalSize = self::GS.'!'."\x00";
        $cut = "\n\n\n".self::GS.'V'."\x41\x03";

        $lines = [
            self::ESC.'@', // initialize printer
            $boldOn.$doubleHeight."ORDER {$order->reference}".$normalSize.$boldOff,
            'Table: '.($order->table?->label ?? $order->table_number ?? '-'),
            $order->guest_count ? "Guests: {$order->guest_count}" : null,
            str_repeat('-', 32),
        ];

        foreach ($items as $item) {
            $lines[] = $boldOn."{$item->quantity} x {$item->product_name}".$boldOff;

            foreach ($item->modifiers as $modifier) {
                $lines[] = "   + {$modifier->modifier_name}";
            }

            if ($item->notes) {
                $lines[] = "   note: {$item->notes}";
            }
        }

        $lines[] = str_repeat('-', 32);
        $lines[] = now()->format('Y-m-d H:i');
        $lines[] = $cut;

        return implode("\n", array_filter($lines, fn ($line) => $line !== null));
    }
}
