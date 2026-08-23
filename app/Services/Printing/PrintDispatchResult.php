<?php

namespace App\Services\Printing;

/**
 * What actually happened when something asked for a ticket.
 *
 * Queueing used to return nothing at all, so every caller answered the same
 * way — a success message — whether three printers had been given work, one
 * copy had been declined as a duplicate, or the order's station had no
 * printer configured and nobody was ever going to cook it. The person
 * standing at the till got the identical green line in all three cases.
 *
 * The point of this object is that "nothing was queued" is a fact a caller
 * can see and say out loud.
 */
class PrintDispatchResult
{
    /**
     * @param  int  $queued  jobs actually handed to the queue
     * @param  array<int, string>  $stationsWithoutPrinter  stations whose
     *                                                      items have nowhere to print
     */
    public function __construct(
        public readonly int $queued = 0,
        public readonly array $stationsWithoutPrinter = [],
    ) {}

    public function queuedSomething(): bool
    {
        return $this->queued > 0;
    }

    /** True when the order has items nobody has been told to make. */
    public function hasUnroutableStations(): bool
    {
        return $this->stationsWithoutPrinter !== [];
    }

    /** The stations with no printer, for a message a human has to act on. */
    public function stationList(): string
    {
        return implode(', ', $this->stationsWithoutPrinter);
    }
}
