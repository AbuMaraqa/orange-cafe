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
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
