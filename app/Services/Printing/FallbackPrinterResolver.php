<?php

namespace App\Services\Printing;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Printer;
use Illuminate\Support\Collection;

/**
 * Where a ticket may go when the printer it was meant for cannot be
 * reached — and, more importantly, where it may not.
 *
 * The rule, and it is asymmetric on purpose:
 *
 *  - A KITCHEN ticket has no stand-in. None. If the grill printer is down,
 *    the steak does not print anywhere and the cashier is told so. Sending
 *    it to the drinks station puts a steak ticket in front of someone who
 *    will not cook it, and worse, someone who may assume it is theirs. A
 *    ticket in the wrong kitchen is more dangerous than no ticket, because
 *    no ticket produces a question and a wrong ticket produces a wrong meal.
 *
 *  - A CASHIER receipt may fall back to the kitchens *this order already
 *    touches*, and only those. The bill has to exist on paper somewhere a
 *    member of staff will find it, and those stations are already handling
 *    this order — the ticket lands beside the food it belongs to. It is
 *    never sent to a kitchen with no part in the order, which would put a
 *    stranger's bill in front of a section that never saw it.
 *
 * This reverses what the code did before. Kitchen tickets used to fall back
 * to the till, on the reasoning that the one person who could walk it over
 * was standing there. The cafe has since decided the opposite: a ticket
 * that appears where it does not belong is worse than a visible gap, and
 * the alert now carries the message the paper used to.
 */
class FallbackPrinterResolver
{
    public const CASHIER_STATION = 'cashier';

    /**
     * Deliberately empty, and deliberately still a method.
     *
     * Returning nothing here — rather than deleting the concept — keeps the
     * call site in the job honest: it asks the routing rules where the
     * ticket may go, and the rules answer "nowhere". A future station that
     * genuinely may stand in for another (two grills side by side, say) has
     * one obvious place to be expressed.
     *
     * @return Collection<int, Printer>
     */
    public function forKitchen(Printer $failed): Collection
    {
        return collect();
    }

    /**
     * The kitchens this order is already being cooked in.
     *
     * Scoped to the order rather than "every non-till printer": a bill
     * printed in a kitchen that has no part in the order is paper nobody
     * is expecting and nobody will connect to a table.
     *
     * @return Collection<int, Printer>
     */
    public function forCashier(Printer $failed, Order $order): Collection
    {
        $order->loadMissing('items');

        $stations = $order->items
            ->map(fn (OrderItem $item) => $item->station ?? 'default')
            ->unique()
            ->reject(fn (?string $station) => $station === self::CASHIER_STATION)
            ->values();

        if ($stations->isEmpty()) {
            return collect();
        }

        return Printer::query()
            ->whereIn('station', $stations)
            ->where('is_active', true)
            ->whereKeyNot($failed->getKey())
            ->get();
    }

    /**
     * The line printed at the top of a stand-in ticket.
     *
     * Without it a bill appearing in the kitchen reads as a bug, or worse
     * as a duplicate to be binned. It has to say what happened and which
     * printer to go and look at.
     */
    public function notice(Printer $failed): string
    {
        return sprintf('⚠ طُبعت هنا لتعذّر الطباعة على: %s (%s)', $failed->name, $failed->station);
    }
}
