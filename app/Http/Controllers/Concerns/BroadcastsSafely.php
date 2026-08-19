<?php

namespace App\Http\Controllers\Concerns;

trait BroadcastsSafely
{
    /**
     * A customer-facing action must never fail just because the Reverb
     * server (or any broadcaster) happens to be down — real-time staff
     * notifications are a nice-to-have on top of the action itself, not a
     * precondition for it.
     */
    protected function safeBroadcast($event, bool $toOthers = false): void
    {
        try {
            $broadcast = broadcast($event);

            if ($toOthers) {
                $broadcast->toOthers();
            }

            // broadcast() sends nothing by itself: it returns a
            // PendingBroadcast that dispatches the event from its
            // destructor. Left to fall out of scope on its own, that happens
            // *after* this try block — so an unreachable Reverb threw right
            // past the catch below and the customer got a 500 for an order
            // that had already been saved. Dropping the last reference here
            // runs the destructor while it is still guarded.
            $broadcast = null;
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
