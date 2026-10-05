<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Expense extends Model
{
    use HasFactory;

    protected $fillable = [
        'expense_category_id', 'user_id', 'amount_cents', 'spent_on',
        'reason', 'notes', 'paid_from_cash', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'spent_on' => 'date',
            'paid_from_cash' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    /** The employee this expense is on, when it is on one. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by')->withTrashed();
    }

    /**
     * The employee-account line this expense produced. At most one — the
     * service updates it in place rather than adding a second, which is
     * what stops an edited amount from being counted twice.
     */
    public function ledgerEntry(): MorphOne
    {
        return $this->morphOne(StaffLedgerEntry::class, 'source');
    }

    /**
     * MorphMany rather than MorphOne even though there is only ever one:
     * cleanup has to be able to remove whatever is there, including a
     * stray second row left by an older version of this code.
     */
    public function cashMovements(): MorphMany
    {
        return $this->morphMany(CashMovement::class, 'source');
    }

    public function scopeOnDate(Builder $query, string $date): Builder
    {
        return $query->whereDate('spent_on', $date);
    }

    /**
     * whereDate on both bounds, not whereBetween.
     *
     * The column is cast to a date but Laravel writes dates in the
     * default Y-m-d H:i:s format, so a row filed today is stored as
     * "...  00:00:00" and compares *greater* than the bare "..." a range
     * ends with. whereBetween therefore drops the last day of every
     * range — which means today never appears in a report that ends
     * today, silently and with no error anywhere.
     */
    public function scopeBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereDate('spent_on', '>=', $from)->whereDate('spent_on', '<=', $to);
    }

    /** Only these reduce the drawer; see the migration for why. */
    public function scopeFromCash(Builder $query): Builder
    {
        return $query->where('paid_from_cash', true);
    }
}
