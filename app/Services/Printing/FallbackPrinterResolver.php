<?php

namespace App\Services\Printing;

use App\Models\Printer;
use Illuminate\Support\Collection;

/**
 * Where a ticket goes when the printer it was meant for cannot be reached.
 *
 * A thermal printer in a kitchen dies at the worst possible moment — mid
 * service, with a full pass — and the ticket that vanishes with it is an
 * order nobody cooks. Retrying alone does not help: the printer may be dead
 * for the rest of the night, and by the time it comes back the table has
 * left.
 *
 * The rule the cafe asked for, and the reason for each half:
 *
 *  - A kitchen ticket falls back to the CASHIER printer only, never to
 *    another kitchen station. Sending the grill's ticket to the drinks
 *    station puts it in front of someone who will not cook it and who now
 *    has to work out whose it is. The till is staffed by the one person who
 *    can walk it over and tell the floor.
 *
 *  - A cashier receipt falls back to the kitchen printers, because there is
 *    nowhere else — and a receipt printed in the kitchen is at least a
 *    receipt that exists and can be carried to the customer.
 */
class FallbackPrinterResolver
{
    public const CASHIER_STATION = 'cashier';

    /**
     * Active printers that can stand in for a broken kitchen printer.
     *
     * @return Collection<int, Printer>
     */
    public function forKitchen(Printer $failed): Collection
    {
        return Printer::query()
            ->where('station', self::CASHIER_STATION)
            ->where('is_active', true)
            ->whereKeyNot($failed->getKey())
            ->get();
    }

    /**
     * Active printers that can stand in for a broken cashier printer —
     * anything that is not itself a till printer.
     *
     * @return Collection<int, Printer>
     */
    public function forCashier(Printer $failed): Collection
    {
        return Printer::query()
            ->where('station', '!=', self::CASHIER_STATION)
            ->where('is_active', true)
            ->whereKeyNot($failed->getKey())
            ->get();
    }

    /**
     * The line printed at the top of a stand-in ticket.
     *
     * Without it, a kitchen ticket appearing at the till reads as a bug, or
     * worse as a duplicate to be thrown away. It has to say what happened
     * and which printer to go and look at.
     */
    public function notice(Printer $failed): string
    {
        return sprintf('⚠ طُبعت هنا لتعذّر الطباعة على: %s (%s)', $failed->name, $failed->station);
    }
}
