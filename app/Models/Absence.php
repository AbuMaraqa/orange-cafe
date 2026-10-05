<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Absence extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'absence_category_id', 'deduction_cents',
        'absent_on', 'reason', 'notes', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'deduction_cents' => 'integer',
            'absent_on' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AbsenceCategory::class, 'absence_category_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by')->withTrashed();
    }

    /**
     * The deduction line on the employee's account. Absent when the
     * absence was not charged for — a zero-value entry would be noise on
     * the statement and would still have to be skipped by every sum.
     */
    public function ledgerEntry(): MorphOne
    {
        return $this->morphOne(StaffLedgerEntry::class, 'source');
    }

    public function scopeOnDate(Builder $query, string $date): Builder
    {
        return $query->whereDate('absent_on', $date);
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
        return $query->whereDate('absent_on', '>=', $from)->whereDate('absent_on', '<=', $to);
    }
}
