<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One handover of money against one bill.
 *
 * The order carries what it has taken in total; this carries *when*, and
 * *how*. A table paying half tonight and the rest tomorrow makes two of
 * these, and the cash box sums them by the day they landed — so the drawer's
 * figures follow the cash rather than the moment the bill happened to close.
 *
 * Not to be confused with CashMovement, which is cash in or out of the drawer
 * for a reason that is *not* a bill.
 */
class OrderPayment extends Model
{
    /**
     * Notes and coins across the counter. The only method that moves the
     * drawer, and therefore the only one Cashbox counts into the balance.
     */
    public const METHOD_CASH = 'cash';

    /**
     * A card at the terminal. Settles the bill without the drawer moving —
     * see the migration that added this column, and Cashbox for what that
     * means for every figure the till reports.
     */
    public const METHOD_CARD = 'card';

    /** @return array<int, string> */
    public static function methods(): array
    {
        return [self::METHOD_CASH, self::METHOD_CARD];
    }

    protected $fillable = ['order_id', 'amount_cents', 'method', 'reference', 'received_at', 'received_by'];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'received_at' => 'datetime',
        ];
    }

    /**
     * The handovers that actually put money in the drawer.
     *
     * Written once, here, because it is the single distinction the cash box
     * rests on and it is asked for in four different queries — the day's
     * takings, the running total behind an opening float, the movement list
     * and the reports. Four hand-written `where('method', 'cash')` clauses
     * are four chances for one of them to be forgotten, and the symptom of
     * forgetting is a till that reports a surplus it cannot account for.
     */
    public function scopeCash(Builder $query): Builder
    {
        return $query->where('method', self::METHOD_CASH);
    }

    /** Settled at the card terminal: real takings, but not in the drawer. */
    public function scopeCard(Builder $query): Builder
    {
        return $query->where('method', self::METHOD_CARD);
    }

    public function isCash(): bool
    {
        return $this->method === self::METHOD_CASH;
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by')->withTrashed();
    }
}
