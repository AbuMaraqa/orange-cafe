<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Cash in or out of the drawer for a reason that is not a customer order.
 *
 * Order takings are NOT here — they live on `orders` and are summed from
 * there. See the migration for why duplicating them would be a trap.
 */
class CashMovement extends Model
{
    use HasFactory;

    public const IN = 'in';

    public const OUT = 'out';

    /** Cash put into the drawer that did not come from a bill. */
    public const TYPE_COLLECTION = 'collection';

    /** The owner topping the float up. */
    public const TYPE_FLOAT = 'float';

    /** Cash paid out for an expense. */
    public const TYPE_EXPENSE = 'expense';

    /** Money handed back to a customer. */
    public const TYPE_REFUND = 'refund';

    /** Cash taken out of the drawer to the bank or the safe. */
    public const TYPE_WITHDRAWAL = 'withdrawal';

    /** An advance handed to an employee out of the drawer. */
    public const TYPE_STAFF_ADVANCE = 'staff_advance';

    protected $fillable = [
        'direction', 'type', 'amount_cents', 'occurred_on',
        'description', 'notes', 'recorded_by', 'source_type', 'source_id',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'occurred_on' => 'date',
        ];
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /** Signed against the drawer, so a day's net is a plain sum. */
    public function signedCents(): int
    {
        return $this->direction === self::IN ? $this->amount_cents : -$this->amount_cents;
    }

    public function scopeOnDate(Builder $query, string $date): Builder
    {
        return $query->whereDate('occurred_on', $date);
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
        return $query->whereDate('occurred_on', '>=', $from)->whereDate('occurred_on', '<=', $to);
    }

    public function scopeIncoming(Builder $query): Builder
    {
        return $query->where('direction', self::IN);
    }

    public function scopeOutgoing(Builder $query): Builder
    {
        return $query->where('direction', self::OUT);
    }
}
