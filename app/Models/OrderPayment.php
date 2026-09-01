<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One handover of money against one bill.
 *
 * The order carries what it has taken in total; this carries *when*. A table
 * paying half tonight and the rest tomorrow makes two of these, and the cash
 * box sums them by the day they landed — so the drawer's figures follow the
 * cash rather than the moment the bill happened to close.
 *
 * Not to be confused with CashMovement, which is cash in or out of the drawer
 * for a reason that is *not* a bill.
 */
class OrderPayment extends Model
{
    protected $fillable = ['order_id', 'amount_cents', 'received_at', 'received_by'];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'received_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
