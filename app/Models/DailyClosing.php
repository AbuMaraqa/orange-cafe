<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyClosing extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_date', 'opening_cents', 'collected_cents', 'expenses_cents',
        'cash_discounts_cents', 'staff_deductions_cents', 'expected_cents',
        'counted_cents', 'difference_cents', 'notes', 'closed_by', 'closed_at',
        'reopened_by', 'reopened_at',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'opening_cents' => 'integer',
            'collected_cents' => 'integer',
            'expenses_cents' => 'integer',
            'cash_discounts_cents' => 'integer',
            'staff_deductions_cents' => 'integer',
            'expected_cents' => 'integer',
            'counted_cents' => 'integer',
            'difference_cents' => 'integer',
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by')->withTrashed();
    }

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by')->withTrashed();
    }

    /**
     * A reopened day is a row that still exists but no longer closes
     * anything — the figures are kept as a record of what was signed off,
     * and the day is editable again.
     */
    public function isClosed(): bool
    {
        return $this->closed_at !== null && $this->reopened_at === null;
    }

    /** 'over' | 'short' | 'balanced' — never rounded away. */
    public function differenceState(): string
    {
        return match (true) {
            $this->difference_cents > 0 => 'over',
            $this->difference_cents < 0 => 'short',
            default => 'balanced',
        };
    }
}
