<?php

namespace App\Services\Printing;

use App\Models\Printer;
use Illuminate\Support\Facades\Log;

/**
 * The one place a printer's state changes, and the one place that decides
 * what "it came back" means.
 *
 * The rule this class exists to enforce, in the cafe's own words: when a
 * dead printer returns, it must **not** print the hour of tickets it
 * missed. A grill printer that reconnects at 13:00 and immediately produces
 * orders #100, #101 and #102 hands the kitchen three meals that were
 * served, cancelled or comped an hour ago — and nothing on the paper
 * distinguishes those from a live order. So recovery stamps the moment, and
 * `mayAutoPrint()` below is what every send consults.
 *
 * Note what this class deliberately does NOT do: it never re-dispatches
 * anything. Recovery is a fact recorded, not an event that flushes a queue.
 * Everything from the outage stays on the reprint screen until a person
 * decides it is still wanted.
 */
class PrinterHealth
{
    /** Reachable, and printing. */
    public const ONLINE = 'online';

    /** Being probed right now. */
    public const CHECKING = 'checking';

    /** Not reachable at all. */
    public const OFFLINE = 'offline';

    /**
     * Reachable, but refusing or ignoring work.
     *
     * Distinct from OFFLINE because it is a different problem with a
     * different fix: a printer that answers the socket but never takes the
     * bytes is out of paper, jammed, or has a stuck spooler — and telling
     * someone "غير متصلة" sends them to check a cable that is fine.
     */
    public const UNRESPONSIVE = 'unresponsive';

    /**
     * How many consecutive failed sends before a printer that is answering
     * is called unresponsive rather than merely unlucky.
     */
    private const UNRESPONSIVE_AFTER = 2;

    /**
     * How many consecutive failures before a printer is called down at all.
     *
     * One is not enough. These printers accept a single TCP connection at a
     * time, so a status check landing while the queue is mid-ticket is
     * refused by a printer that is working perfectly — and the check ran
     * every minute, all day, giving it plenty of chances.
     *
     * Each of those blips used to read as "the printer went down", and the
     * next successful check as "it came back", which stamped a recovery.
     * See recovery_at below for why that was so expensive.
     */
    private const OFFLINE_AFTER = 2;

    /**
     * How long a printer has to be down before coming back counts as a
     * recovery.
     *
     * `recovered_at` blocks every ticket for an order placed before it, and
     * that is right after a real outage: a printer reconnecting at 13:00
     * must not spit out the orders it missed since 12:00. It is ruinous for
     * a blip. A check that failed once and succeeded a minute later stamped
     * a recovery, and every ticket in the building was held behind it —
     * silently, because a held job sits at "pending" with nothing on fire.
     *
     * An outage shorter than this is not an outage. Nothing was missed
     * while it lasted, so there is no backlog to protect anyone from.
     */
    private const REAL_OUTAGE_SECONDS = 180;

    /**
     * Records a successful send or probe.
     *
     * Returns true when this was a *recovery* — the printer was down and is
     * now up — so the caller can tell the floor. The stamp it writes is
     * what stops the backlog from printing itself.
     */
    public function markReachable(Printer $printer, ?\DateTimeInterface $printedOrderAt = null): bool
    {
        $wasDown = in_array($printer->status, [self::OFFLINE, self::UNRESPONSIVE], true);

        // Decided before the fill below clears offline_since, which is the
        // only record of how long the outage lasted.
        $missedWork = $wasDown && $this->wasDownLongEnoughToMissWork($printer);

        $printer->forceFill([
            'status' => self::ONLINE,
            'status_checked_at' => now(),
            'last_error' => null,
            'consecutive_failures' => 0,
            'offline_since' => null,
        ]);

        // Only a real outage earns the stamp.
        //
        // The stamp blocks every ticket for an order placed before it, so
        // it has to mean "this printer missed things". A printer that
        // failed one check and answered the next missed nothing — the
        // queue was not even trying to print during that second — and
        // stamping it held every ticket in the building behind a printer
        // that had never actually stopped working.
        if ($missedWork) {
            // The line in the sand. Only orders created after this moment
            // may print on this printer without a human asking.
            //
            // Except the order that proved the printer works. A recovery is
            // usually noticed *by* a successful send, and that send belongs
            // to an order created a second or two earlier — so stamping
            // `now()` held the rest of that same order: one station printed,
            // the others were filed as backlog from an outage that had just
            // ended. A table's food arriving from one printer and not the
            // next is exactly the failure this stamp exists to prevent, and
            // it was causing it.
            //
            // Backing the line up to that order keeps the real guarantee —
            // the hour of tickets missed during the outage still waits for a
            // person — while letting the order in hand finish printing.
            $printer->recovered_at = $printedOrderAt !== null && $printedOrderAt < now()
                ? $printedOrderAt
                : now();

            Log::info("Printer recovered after a real outage: {$printer->name} ({$printer->station}). Older tickets stay unprinted until someone asks for them.");
        } elseif ($wasDown) {
            Log::info("Printer answered again after a brief blip: {$printer->name} ({$printer->station}). Nothing held.");
        }

        $printer->save();

        return $wasDown;
    }

    /**
     * Records a failed send or probe.
     *
     * `$reachedPrinter` separates the two failures that look the same from
     * the outside: false means nothing answered, true means something did
     * and then would not take the job.
     */
    public function markUnreachable(Printer $printer, string $error, bool $reachedPrinter = false): void
    {
        $failures = $printer->consecutive_failures + 1;

        // One failure is not "down".
        //
        // These printers take one connection at a time, so a check landing
        // while a ticket is printing is refused by a printer that is
        // working. Calling that down — and the next success a recovery —
        // is what silently held every ticket in the cafe.
        //
        // Below the threshold the status is left exactly as it was; the
        // failure is still counted and its reason still recorded, so a
        // printer that really is off crosses the line on the next check a
        // minute later.
        $status = match (true) {
            $failures < self::OFFLINE_AFTER => $printer->status ?? self::ONLINE,
            $reachedPrinter && $failures >= self::UNRESPONSIVE_AFTER => self::UNRESPONSIVE,
            default => self::OFFLINE,
        };

        $printer->forceFill([
            'status' => $status,
            'status_checked_at' => now(),
            // Truncated: a socket error can carry a whole stack trace, and
            // the printers screen has one line to show it in.
            'last_error' => mb_substr($error, 0, 500),
            'consecutive_failures' => $failures,
            // Only stamped on the way down, so it keeps saying how long it
            // has been out rather than resetting on every failed retry.
            'offline_since' => $printer->offline_since ?? now(),
        ])->save();
    }

    /**
     * Was this printer down long enough to have missed anything?
     *
     * `offline_since` is stamped once on the way down and cleared on the
     * way up, so it measures the whole outage rather than the last retry.
     * A printer with no stamp was never recorded as down at all — the first
     * check of a fresh install, for instance, which used to count as a
     * recovery and hold every ticket in the database from the moment the
     * app was installed.
     */
    private function wasDownLongEnoughToMissWork(Printer $printer): bool
    {
        if ($printer->offline_since === null) {
            return false;
        }

        return $printer->offline_since->diffInSeconds(now()) >= self::REAL_OUTAGE_SECONDS;
    }

    /** Shown while a probe is in flight, so the screen is never blank. */
    public function markChecking(Printer $printer): void
    {
        $printer->forceFill(['status' => self::CHECKING])->save();
    }

    /**
     * May this ticket be sent without anyone asking?
     *
     * No, if the order predates the printer's most recent recovery. That is
     * the whole rule: new orders print, the backlog waits for a person.
     *
     * A printer that has never been down has no `recovered_at`, so nothing
     * is ever blocked on a healthy printer.
     */
    public function mayAutoPrint(Printer $printer, \DateTimeInterface $orderCreatedAt): bool
    {
        if ($printer->recovered_at === null) {
            return true;
        }

        return $orderCreatedAt >= $printer->recovered_at;
    }

    /** Down in either of the two ways. */
    public function isDown(Printer $printer): bool
    {
        return in_array($printer->status, [self::OFFLINE, self::UNRESPONSIVE], true);
    }
}
