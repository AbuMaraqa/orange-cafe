<?php

namespace App\Services\Accounting;

use App\Models\StaffLedgerEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The only thing that writes to an employee's account.
 *
 * Everything that can affect a balance goes through `record()`, and
 * `record()` is keyed on the record that caused it. That is what makes the
 * rule the cafe actually asked for possible: an absence whose deduction is
 * edited from 50 to 30 must end up with one line worth 30, not two lines
 * worth 80. Callers therefore never create entries directly — they say
 * "this absence is worth 30 now" and get told the result.
 */
class StaffLedger
{
    /**
     * Create, update, or remove the single entry belonging to `$source`.
     *
     * Passing zero deletes it. That is the same statement as "this absence
     * costs nothing": a zero-value line would sit on the statement adding
     * nothing, and every total would have to remember to skip it.
     *
     * @param  int  $amountCents  always positive; direction comes from the type
     */
    public function record(
        Model $source,
        User|int $user,
        string $type,
        int $amountCents,
        string $occurredOn,
        string $description,
        ?string $notes = null,
        ?int $recordedBy = null,
    ): ?StaffLedgerEntry {
        $existing = StaffLedgerEntry::query()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->first();

        if ($amountCents <= 0) {
            $existing?->delete();

            return null;
        }

        $attributes = [
            'user_id' => $user instanceof User ? $user->id : $user,
            'type' => $type,
            'amount_cents' => $this->signed($type, $amountCents),
            'occurred_on' => $occurredOn,
            'description' => $description,
            'notes' => $notes,
        ];

        if ($existing) {
            // recorded_by is deliberately not overwritten: it says who
            // created the movement, and an edit by someone else does not
            // change that fact.
            $existing->update($attributes);

            return $existing;
        }

        return StaffLedgerEntry::create([
            ...$attributes,
            'recorded_by' => $recordedBy,
            'source_type' => $source->getMorphClass(),
            'source_id' => $source->getKey(),
        ]);
    }

    /**
     * A standalone movement with no originating record — a salary, a
     * repayment, a manual correction.
     */
    public function post(
        User|int $user,
        string $type,
        int $amountCents,
        string $occurredOn,
        string $description,
        ?string $notes = null,
        ?int $recordedBy = null,
    ): StaffLedgerEntry {
        return StaffLedgerEntry::create([
            'user_id' => $user instanceof User ? $user->id : $user,
            'type' => $type,
            'amount_cents' => $this->signed($type, $amountCents),
            'occurred_on' => $occurredOn,
            'description' => $description,
            'notes' => $notes,
            'recorded_by' => $recordedBy,
        ]);
    }

    /** Drops whatever entry `$source` owns, if any. */
    public function forget(Model $source): void
    {
        StaffLedgerEntry::query()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->delete();
    }

    /**
     * The statement: every line in order, each with the balance as it
     * stood after that line.
     *
     * Computed in one pass here rather than by a window function so it
     * reads the same on SQLite (the packaged app) and MySQL (an imported
     * install), which are both in use.
     *
     * @return array<int, array<string, mixed>>
     */
    public function statement(User $user): array
    {
        $balance = 0;

        return $user->ledgerEntries()->with('recordedBy')->get()
            ->map(function (StaffLedgerEntry $entry) use (&$balance) {
                $balance += $entry->amount_cents;

                return [
                    'id' => $entry->id,
                    'date' => $entry->occurred_on->toDateString(),
                    'type' => $entry->type,
                    'description' => $entry->description,
                    'notes' => $entry->notes,
                    'debit_cents' => $entry->debitCents(),
                    'credit_cents' => $entry->creditCents(),
                    'balance_cents' => $balance,
                    'recorded_by_name' => $entry->recordedBy?->name,
                    'source_type' => $entry->source_type,
                    'source_id' => $entry->source_id,
                ];
            })
            ->all();
    }

    /**
     * The headline figures on an employee's page, each one a sum of the
     * entries behind it so the screen can drill into any of them.
     *
     * @return array<string, int>
     */
    public function summary(User $user): array
    {
        $byType = $user->ledgerEntries()
            ->selectRaw('type, sum(amount_cents) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $total = fn (string $type) => abs((int) ($byType[$type] ?? 0));

        return [
            'advances_cents' => $total(StaffLedgerEntry::TYPE_ADVANCE),
            'expenses_cents' => $total(StaffLedgerEntry::TYPE_EXPENSE),
            'absence_deductions_cents' => $total(StaffLedgerEntry::TYPE_ABSENCE_DEDUCTION),
            'other_deductions_cents' => $total(StaffLedgerEntry::TYPE_OTHER_DEDUCTION),
            'salary_cents' => $total(StaffLedgerEntry::TYPE_SALARY),
            'payments_cents' => $total(StaffLedgerEntry::TYPE_PAYMENT),
            'adjustments_cents' => $total(StaffLedgerEntry::TYPE_ADJUSTMENT),
            'balance_cents' => (int) $byType->sum(),
        ];
    }

    private function signed(string $type, int $amountCents): int
    {
        $direction = StaffLedgerEntry::DIRECTIONS[$type] ?? 1;

        return $direction * abs($amountCents);
    }
}
