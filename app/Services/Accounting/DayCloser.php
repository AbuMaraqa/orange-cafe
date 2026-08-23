<?php

namespace App\Services\Accounting;

use App\Models\DailyClosing;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Signing off a day.
 *
 * The figures are snapshotted, not referenced. A closing says "on the
 * night of the 20th we counted 1,240 against an expected 1,250 and were 10
 * short" — and that has to stay true even after a refund is processed next
 * week or an expense is backdated into that day. Recomputing on read would
 * quietly rewrite a number a manager put their name to.
 */
class DayCloser
{
    public function __construct(private Cashbox $cashbox) {}

    /**
     * @param  int  $countedCents  what was actually in the drawer
     */
    public function close(string $date, int $countedCents, ?string $notes, User $by): DailyClosing
    {
        return DB::transaction(function () use ($date, $countedCents, $notes, $by) {
            if ($this->cashbox->isClosed($date)) {
                throw new RuntimeException(__('accounting.day_already_closed'));
            }

            $day = $this->cashbox->day($date);
            $expected = $day['balance_cents'];

            $figures = [
                'opening_cents' => $day['opening_cents'],
                'collected_cents' => $day['total_in_cents'],
                'expenses_cents' => $day['expenses_cents'],
                'cash_discounts_cents' => $day['manual_out_cents'],
                'staff_deductions_cents' => $day['staff_deductions_cents'],
                'expected_cents' => $expected,
                'counted_cents' => $countedCents,
                'difference_cents' => $countedCents - $expected,
                'notes' => $notes,
                'closed_by' => $by->id,
                'closed_at' => now(),
                // Clearing these is what makes the row current again rather
                // than a record of the last reopening.
                'reopened_by' => null,
                'reopened_at' => null,
            ];

            // Found with whereDate, not updateOrCreate on the raw value.
            // `business_date` is cast to a date but Laravel writes dates with
            // the default 'Y-m-d H:i:s' format, so the stored value is
            // "2026-08-20 00:00:00" and an equality match against the
            // "2026-08-20" this method is given never finds the row. It would
            // then try to insert a second closing for the same day and die on
            // the unique key — which is only reachable after a reopen, so it
            // would have shipped and only failed for the manager trying to
            // correct a night that was already wrong.
            $closing = DailyClosing::query()->whereDate('business_date', $date)->first();

            if ($closing) {
                $closing->update($figures);

                return $closing;
            }

            return DailyClosing::create(['business_date' => $date, ...$figures]);
        });
    }

    /**
     * Reopening is a recorded act, not an absence of one.
     *
     * A closed day that can be quietly edited is not a close. The stamp is
     * what lets anyone reviewing the month see that the 19th was reopened,
     * by whom, and when.
     */
    public function reopen(string $date, User $by): DailyClosing
    {
        $closing = DailyClosing::query()->whereDate('business_date', $date)->first();

        if (! $closing || ! $closing->isClosed()) {
            throw new RuntimeException(__('accounting.day_not_closed'));
        }

        $closing->update([
            'reopened_by' => $by->id,
            'reopened_at' => now(),
        ]);

        return $closing;
    }
}
