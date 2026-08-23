<?php

namespace App\Http\Controllers\Admin\Accounting\Concerns;

use App\Services\Accounting\BusinessDay;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Every money screen is "a day" or "a stretch of days", and they all have
 * to agree on what that means.
 *
 * Defaulting `to` to today and `from` to the first of this month rather
 * than to the same day: the question these screens exist to answer is
 * "where has the month gone", and a range that starts empty makes the user
 * pick two dates before they can see anything at all.
 *
 * A range also carries a time of day, optionally. A café that closes at 3am
 * has a night that lands on two calendar dates, and a day-granular range
 * cuts it in half: the last three hours of trade are filed under tomorrow,
 * where they are read as a second, nearly empty day. `from_time`/`to_time`
 * let the range say "yesterday 10:00 until today 03:00" and have that be
 * one night.
 *
 * Both are returned, because the money is not all stored the same way:
 * `from`/`to` are dates, for the columns that are dates (an expense is
 * spent on a day, a closing belongs to a business date, an absence is a day
 * off — none of them have a clock reading). `from_at`/`to_at` are the full
 * moments, for the columns that are timestamps — payments, above all.
 * Anything reading a date column keeps using the dates and simply ignores
 * the time, which is the honest thing for it to do rather than pretending
 * to a precision it does not have.
 */
trait ResolvesDateRange
{
    /**
     * @return array{
     *     from: string, to: string,
     *     from_at: string, to_at: string,
     *     from_time: ?string, to_time: ?string
     * }
     */
    protected function dateRange(Request $request): array
    {
        $to = $request->filled('to')
            ? Carbon::parse($request->input('to'))
            : Carbon::now();

        $from = $request->filled('from')
            ? Carbon::parse($request->input('from'))
            : $to->copy()->startOfMonth();

        $fromTime = $this->timeOfDay($request->input('from_time'));
        $toTime = $this->timeOfDay($request->input('to_time'));

        // No time given means the whole of that day — the café's own day,
        // which need not start at midnight (see BusinessDay), so a report
        // for "the 23rd" covers the same hours the cash box counted for the
        // 23rd. A time typed into the filter overrides it: that is somebody
        // asking about a specific stretch of clock, not about a day.
        $businessDay = app(BusinessDay::class);

        $fromAt = $fromTime
            ? $from->copy()->setTimeFromTimeString($fromTime)
            : $businessDay->startOf($from);

        $toAt = $toTime
            ? $to->copy()->setTimeFromTimeString($toTime)->endOfMinute()
            : $businessDay->endOf($to);

        // A range typed backwards ("من 25 إلى 5") returns nothing and looks
        // like missing data rather than a mistake, so it is swapped instead.
        // Compared as moments, so 10:00→03:00 on one date is caught too.
        if ($fromAt->gt($toAt)) {
            [$from, $to] = [$to, $from];
            [$fromAt, $toAt] = [$toAt, $fromAt];
            [$fromTime, $toTime] = [$toTime, $fromTime];
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'from_at' => $fromAt->toDateTimeString(),
            'to_at' => $toAt->toDateTimeString(),
            'from_time' => $fromTime,
            'to_time' => $toTime,
        ];
    }

    protected function singleDate(Request $request): string
    {
        return $request->filled('date')
            ? Carbon::parse($request->input('date'))->toDateString()
            : Carbon::now()->toDateString();
    }

    /**
     * A time as the browser's time input writes it, or nothing.
     *
     * Nothing is returned for anything unparseable rather than throwing:
     * this comes from a query string, and a hand-edited URL should fall
     * back to "the whole day" instead of a 500 on a money screen.
     */
    private function timeOfDay(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', trim($value), $parts)) {
            return null;
        }

        return "{$parts[1]}:{$parts[2]}";
    }
}
