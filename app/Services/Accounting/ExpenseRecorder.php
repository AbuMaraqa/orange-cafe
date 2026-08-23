<?php

namespace App\Services\Accounting;

use App\Models\CashMovement;
use App\Models\Expense;
use App\Models\StaffLedgerEntry;
use Illuminate\Support\Facades\DB;

/**
 * Saving an expense is three writes, and they must never disagree.
 *
 * One form submission can touch:
 *   1. the expense itself — what was spent, on what, by whom;
 *   2. the drawer, when it was paid in cash;
 *   3. the employee's account, when it was on an employee.
 *
 * Which of the three happen depends on two independent flags, and every
 * one of them is keyed back to the expense. So editing an expense from 200
 * to 150 moves all three to 150, and unticking "من الصندوق" removes the
 * cash movement rather than leaving a stale one behind — the failure mode
 * that would otherwise show up as a till that never balances again.
 *
 * All of it inside one transaction: a half-saved expense is worse than a
 * rejected one, because nothing on screen says which half survived.
 */
class ExpenseRecorder
{
    public function __construct(private StaffLedger $ledger) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?int $recordedBy): Expense
    {
        return DB::transaction(function () use ($data, $recordedBy) {
            $expense = Expense::create([...$data, 'recorded_by' => $recordedBy]);

            $this->sync($expense, $recordedBy);

            return $expense;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Expense $expense, array $data): Expense
    {
        return DB::transaction(function () use ($expense, $data) {
            $expense->update($data);

            $this->sync($expense, $expense->recorded_by);

            return $expense->refresh();
        });
    }

    /**
     * Removes the expense and everything it caused.
     *
     * Cascade would handle neither: the ledger entry and the cash movement
     * are polymorphic, so the database has no foreign key to act on. Doing
     * it here keeps the three in step.
     */
    public function delete(Expense $expense): void
    {
        DB::transaction(function () use ($expense) {
            $this->ledger->forget($expense);
            $expense->cashMovements()->delete();
            $expense->delete();
        });
    }

    /**
     * Brings the drawer and the employee account into line with whatever
     * the expense currently says.
     */
    private function sync(Expense $expense, ?int $recordedBy): void
    {
        $expense->loadMissing('category', 'user');

        $description = $expense->reason !== ''
            ? $expense->reason
            : ($expense->category?->name ?? '');

        // --- the drawer -------------------------------------------------
        if ($expense->paid_from_cash) {
            $movement = $expense->cashMovements()->first();

            $attributes = [
                'direction' => CashMovement::OUT,
                'type' => $expense->user_id
                    ? CashMovement::TYPE_STAFF_ADVANCE
                    : CashMovement::TYPE_EXPENSE,
                'amount_cents' => $expense->amount_cents,
                'occurred_on' => $expense->spent_on->toDateString(),
                'description' => $description,
                'notes' => $expense->notes,
            ];

            if ($movement) {
                $movement->update($attributes);
            } else {
                $expense->cashMovements()->create([...$attributes, 'recorded_by' => $recordedBy]);
            }
        } else {
            // Switched to a non-cash payment after the fact — the drawer
            // must give the money back.
            $expense->cashMovements()->delete();
        }

        // --- the employee's account -------------------------------------
        if ($expense->user_id) {
            $this->ledger->record(
                source: $expense,
                user: $expense->user_id,
                type: StaffLedgerEntry::TYPE_EXPENSE,
                amountCents: $expense->amount_cents,
                occurredOn: $expense->spent_on->toDateString(),
                description: $description,
                notes: $expense->notes,
                recordedBy: $recordedBy,
            );
        } else {
            // Detached from the employee it used to be on.
            $this->ledger->forget($expense);
        }
    }
}
