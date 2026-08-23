<?php

namespace App\Services\Accounting;

use App\Models\Absence;
use App\Models\StaffLedgerEntry;
use Illuminate\Support\Facades\DB;

/**
 * An absence and the deduction it produces, kept as one thing.
 *
 * The deduction is not a separate step the user performs — recording the
 * absence *is* recording the deduction. Editing 50 down to 30 rewrites the
 * same account line; setting it to 0 removes the line entirely (the
 * absence stays, it just stopped costing anything); deleting the absence
 * takes the line with it.
 *
 * Nothing here touches the cash box, deliberately. A deduction removes no
 * cash from the drawer — it reduces what the cafe owes at month end. See
 * Cashbox for what that would break.
 */
class AbsenceRecorder
{
    public function __construct(private StaffLedger $ledger) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?int $recordedBy): Absence
    {
        return DB::transaction(function () use ($data, $recordedBy) {
            $absence = Absence::create([...$data, 'recorded_by' => $recordedBy]);

            $this->sync($absence, $recordedBy);

            return $absence;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Absence $absence, array $data): Absence
    {
        return DB::transaction(function () use ($absence, $data) {
            $absence->update($data);

            $this->sync($absence, $absence->recorded_by);

            return $absence->refresh();
        });
    }

    public function delete(Absence $absence): void
    {
        DB::transaction(function () use ($absence) {
            $this->ledger->forget($absence);
            $absence->delete();
        });
    }

    private function sync(Absence $absence, ?int $recordedBy): void
    {
        $absence->loadMissing('category');

        $categoryName = $absence->category?->name ?? '';

        // The statement line has to stand on its own — "غياب بدون عذر" a
        // month later, without the reader going back to find the absence.
        $description = $absence->reason
            ? "{$categoryName} — {$absence->reason}"
            : $categoryName;

        $this->ledger->record(
            source: $absence,
            user: $absence->user_id,
            type: StaffLedgerEntry::TYPE_ABSENCE_DEDUCTION,
            amountCents: $absence->deduction_cents,
            occurredOn: $absence->absent_on->toDateString(),
            description: $description,
            notes: $absence->notes,
            recordedBy: $recordedBy,
        );
    }
}
