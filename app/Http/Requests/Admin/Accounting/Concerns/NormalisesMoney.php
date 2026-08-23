<?php

namespace App\Http\Requests\Admin\Accounting\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The forms speak currency units; the database speaks cents.
 *
 * The conversion happens here rather than in the browser on purpose. A
 * client that sends cents is a client that can send the wrong ones — a
 * rounding difference in JavaScript, or an older cached bundle after a
 * deploy — and the resulting expense is off by a factor of a hundred with
 * nothing to flag it. The request receives what the user typed and is the
 * one place that turns it into money.
 */
trait NormalisesMoney
{
    /**
     * Used by both FormRequests and controllers.
     *
     * In a FormRequest `$this` *is* the request, so it can be used
     * directly; in a controller it is not, and the trait has to reach for
     * the current one. Resolving it here rather than making every caller
     * pass it keeps the two call styles identical, which matters because a
     * missed conversion is an amount off by a factor of a hundred.
     */
    protected function moneyRequest(): Request
    {
        return $this instanceof Request ? $this : request();
    }

    /**
     * Accepts either `amount` (currency units, what the form sends) or the
     * cents field itself (what tests and any future API send).
     */
    protected function normaliseAmount(string $centsKey, string $inputKey = 'amount'): void
    {
        $request = $this->moneyRequest();

        if (! $request->has($inputKey)) {
            return;
        }

        $raw = $request->input($inputKey);

        if ($raw === null || $raw === '') {
            // Left for the rules to reject or accept — guessing zero here
            // would turn "I forgot the amount" into a valid entry.
            $request->merge([$centsKey => null]);

            return;
        }

        // round(), not (int) cast: 12.10 * 100 is 1209.9999... in binary
        // floating point, and casting truncates it to 1209 — a piastre
        // vanishing from roughly one entry in ten, which is exactly the kind
        // of drift that makes a till fail to reconcile for no visible reason.
        $request->merge([$centsKey => (int) round(((float) $raw) * 100)]);
    }

    /**
     * "التاريخ = تاريخ اليوم تلقائيًا" — the form leaves it out and the
     * common case costs nobody a keystroke.
     */
    protected function defaultToToday(string $key): void
    {
        $request = $this->moneyRequest();

        if (! $request->filled($key)) {
            $request->merge([$key => Carbon::now()->toDateString()]);
        }
    }
}
