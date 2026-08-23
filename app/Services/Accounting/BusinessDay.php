<?php

namespace App\Services\Accounting;

use App\Settings\RestaurantSettings;
use Illuminate\Support\Carbon;

/**
 * What "a day" means to a café that is still serving after midnight.
 *
 * The trading day starts at a clock reading the owner sets, not at 00:00.
 * A sale at 01:30 on the 24th, in a venue whose day starts at 06:00, is
 * part of the 23rd: the same shift, the same staff, the same drawer, which
 * will be counted once at three in the morning. Filed by calendar date it
 * lands in a second, nearly empty day, and neither half of the night can be
 * checked against the till roll.
 *
 * Everything that reads a *timestamp* — payments, above all — goes through
 * here. Things recorded as a date the operator typed (an expense, a day
 * off, a closing's business_date) do not: they were already answers to the
 * question "which day", not moments to be classified.
 *
 * With the setting left at "00:00" every method here returns exactly what
 * plain calendar arithmetic returned before it existed, which is what makes
 * it safe to route the existing figures through it.
 */
class BusinessDay
{
    public function __construct(private RestaurantSettings $settings) {}

    /** Minutes past midnight the trading day begins at. */
    public function startsAtMinutes(): int
    {
        $raw = trim((string) $this->settings->business_day_starts_at);

        if (! preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $raw, $parts)) {
            return 0;
        }

        return ((int) $parts[1]) * 60 + (int) $parts[2];
    }

    public function startsAt(): string
    {
        $minutes = $this->startsAtMinutes();

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /** Whether the café's day is simply the calendar day. */
    public function isCalendarDay(): bool
    {
        return $this->startsAtMinutes() === 0;
    }

    /**
     * Which trading day a moment belongs to.
     *
     * Anything before the start hour belongs to the date before — the shift
     * that was still running when the clock rolled over.
     */
    public function dateOf(Carbon|string $moment): string
    {
        $moment = $moment instanceof Carbon ? $moment->copy() : Carbon::parse($moment);

        $minutes = $moment->hour * 60 + $moment->minute;

        return $minutes < $this->startsAtMinutes()
            ? $moment->subDay()->toDateString()
            : $moment->toDateString();
    }

    /** The moment a trading day opens. */
    public function startOf(Carbon|string $date): Carbon
    {
        return $this->dateAt($date)->addMinutes($this->startsAtMinutes());
    }

    /**
     * The last instant of a trading day — the second before the next one
     * opens, so consecutive days meet without overlapping or leaving a gap
     * a payment could fall into.
     */
    public function endOf(Carbon|string $date): Carbon
    {
        return $this->startOf($date)->addDay()->subSecond();
    }

    /** The trading day happening right now. */
    public function today(): string
    {
        return $this->dateOf(Carbon::now());
    }

    private function dateAt(Carbon|string $date): Carbon
    {
        return ($date instanceof Carbon ? $date->copy() : Carbon::parse($date))->startOfDay();
    }
}
