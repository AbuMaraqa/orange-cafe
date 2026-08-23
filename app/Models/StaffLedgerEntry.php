<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One movement on an employee's account.
 *
 * The sign convention, which everything else here depends on:
 *
 *   positive  the employee owes the cafe   (سلفة taken, غياب deducted)
 *   negative  the cafe owes the employee   (راتب earned, a repayment made)
 *
 * So the balance is a plain sum, and a positive balance means "this person
 * owes us this much". The statement screen splits the sign back into مدين
 * and دائن columns; nothing stores those separately, because two columns
 * of which exactly one must always be zero is a shape that eventually
 * holds a row with both filled in.
 */
class StaffLedgerEntry extends Model
{
    use HasFactory;

    /** An advance handed to the employee in cash. */
    public const TYPE_ADVANCE = 'advance';

    /** Something bought for or paid on behalf of the employee. */
    public const TYPE_EXPENSE = 'expense';

    /** Charged for an absence. */
    public const TYPE_ABSENCE_DEDUCTION = 'absence_deduction';

    /** Any other deduction decided by the manager. */
    public const TYPE_OTHER_DEDUCTION = 'other_deduction';

    /** Salary earned — owed to the employee. */
    public const TYPE_SALARY = 'salary';

    /** Money the employee handed back, or was paid out. */
    public const TYPE_PAYMENT = 'payment';

    /** A manual correction. */
    public const TYPE_ADJUSTMENT = 'adjustment';

    /**
     * Which way each type moves the balance, so a caller can pass a plain
     * positive amount and never has to remember the convention above.
     *
     * @var array<string, int>
     */
    public const DIRECTIONS = [
        self::TYPE_ADVANCE => 1,
        self::TYPE_EXPENSE => 1,
        self::TYPE_ABSENCE_DEDUCTION => 1,
        self::TYPE_OTHER_DEDUCTION => 1,
        self::TYPE_SALARY => -1,
        self::TYPE_PAYMENT => -1,
        self::TYPE_ADJUSTMENT => 1,
    ];

    protected $fillable = [
        'user_id', 'type', 'amount_cents', 'occurred_on',
        'description', 'notes', 'recorded_by', 'source_type', 'source_id',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'occurred_on' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** The absence or expense that produced this line, when there was one. */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /** Charged to the employee. */
    public function debitCents(): int
    {
        return max($this->amount_cents, 0);
    }

    /** Credited to them. */
    public function creditCents(): int
    {
        return max(-$this->amount_cents, 0);
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Oldest first, and ties broken by id.
     *
     * Two entries on the same date are common — an advance in the morning
     * and a deduction that afternoon — and without the tiebreak the running
     * balance column would reorder itself between page loads, which looks
     * exactly like the numbers being wrong.
     */
    public function scopeChronological(Builder $query): Builder
    {
        return $query->orderBy('occurred_on')->orderBy('id');
    }
}
