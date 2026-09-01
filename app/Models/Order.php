<?php

namespace App\Models;

use App\Observers\OrderObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

// Records a row on the payment timeline whenever the bill's running total
// moves — including from the legacy importer and from seeders, neither of
// which goes near the till. See OrderObserver.
#[ObservedBy(OrderObserver::class)]
class Order extends Model
{
    protected $fillable = [
        'table_id', 'guest_count', 'table_number', 'customer_name',
        'status', 'locale', 'notes', 'total_cents', 'discount_cents', 'discount_reason',
        'created_by', 'paid_at', 'paid_by', 'amount_paid_cents',
    ];

    protected function casts(): array
    {
        return [
            // Not decoration: a freshly created order still holds the raw
            // request value here, and `table_id` arrives from an HTTP form as
            // the string "10". OrderPlaced broadcasts that object before it
            // is ever read back from the database, so the socket payload
            // carried a string while every table id rendered into the page
            // came from the database as a number.
            //
            // useStaffFloorPlan matches them with ===, so 10 === "10" was
            // false and the waiter's floor plan could not find the table the
            // order belonged to: a customer's QR order silently landed in the
            // "no table" list instead of lighting up its own table, and the
            // screen looked like it had received nothing at all. The kitchen
            // was unaffected — it appends to a flat list and never looks a
            // table up — which is what made it read like a waiter-screen bug.
            'table_id' => 'integer',
            'guest_count' => 'integer',
            'total_cents' => 'integer',
            'discount_cents' => 'integer',
            'amount_paid_cents' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            $order->reference ??= static::generateReference();
        });
    }

    public static function generateReference(): string
    {
        do {
            $reference = now()->format('ymdHis').random_int(0, 9);
        } while (static::where('reference', $reference)->exists());

        return $reference;
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'table_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** Every piece of paper this order should have produced, printed or not. */
    public function printJobs(): HasMany
    {
        return $this->hasMany(PrintJob::class);
    }

    /**
     * Each handover of money against this bill, oldest first.
     *
     * `amount_paid_cents` says how much this bill has taken; this says when
     * each part of it arrived, which is the only question the cash box can
     * answer a running total with. See the order_payments migration.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(OrderPayment::class)->orderBy('received_at')->orderBy('id');
    }

    /**
     * Records money crossing the counter, and moves the bill's running total
     * with it.
     *
     * The two are written together, here, for the same reason an expense and
     * its cash movement are written together in ExpenseRecorder: they are one
     * fact stored twice, and the day they disagree is the day the drawer
     * stops reconciling with no way to tell which half is wrong.
     *
     * A zero delta writes nothing. Re-saving a bill at the amount it already
     * shows is not a payment, and a row worth nothing on the timeline is a
     * line on the cash box's movement list that a manager has to work out is
     * meaningless.
     *
     * @param  int  $deltaCents  what changed hands now — negative when a bill
     *                           is corrected downwards and money goes back
     */
    public function recordPayment(int $deltaCents, ?int $userId = null, ?Carbon $at = null): ?OrderPayment
    {
        if ($deltaCents === 0) {
            return null;
        }

        return $this->payments()->create([
            'amount_cents' => $deltaCents,
            'received_at' => $at ?? now(),
            'received_by' => $userId,
        ]);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    /**
     * What the items come to before any discount — the honest sum of the
     * lines, which is also what the kitchen cooked.
     */
    public function itemsSubtotalCents(): int
    {
        return (int) $this->items()->sum('subtotal_cents');
    }

    /**
     * Re-derives what the table owes: the lines, less the discount.
     *
     * Summing rather than adjusting by a difference is deliberate. Money is
     * the one place where a running balance that drifts by a cent per edit
     * is not acceptable, and a bill gets edited repeatedly at the till.
     *
     * A discount larger than the remaining lines (items removed after it
     * was agreed) settles at zero rather than going negative — the customer
     * is not owed money for ordering less.
     */
    public function recalculateTotal(): void
    {
        $subtotal = $this->itemsSubtotalCents();

        $this->forceFill([
            'discount_cents' => min($this->discount_cents ?? 0, $subtotal),
            'total_cents' => max(0, $subtotal - ($this->discount_cents ?? 0)),
        ])->save();
    }

    public function isPaid(): bool
    {
        return $this->paid_at !== null;
    }

    /**
     * What was actually collected — may differ from total_cents when a
     * cashier rounds down or discounts at the till. Falls back to
     * total_cents for orders paid before this was tracked.
     */
    public function collectedCents(): int
    {
        return $this->amount_paid_cents ?? $this->total_cents;
    }

    /**
     * What has actually been handed over so far.
     *
     * Distinct from collectedCents(), which answers "what did this order
     * bring in" for a settled bill and falls back to the total for old rows
     * that predate the column. This one is about a bill still being paid,
     * where a null means nothing has been paid yet — not "assume the lot".
     */
    public function paidSoFarCents(): int
    {
        return $this->amount_paid_cents ?? 0;
    }

    /**
     * What is still owed on this bill.
     *
     * Never negative: a customer who overpays (a tip, or a note larger than
     * the bill) owes nothing, and reporting that as a negative balance
     * would put it back into the day's outstanding figure with the wrong
     * sign.
     */
    public function outstandingCents(): int
    {
        return max(0, $this->total_cents - $this->paidSoFarCents());
    }

    /**
     * Money has been taken, but not all of it.
     *
     * The state that had nowhere to live before: a table paying half now
     * and half later had to be either fully settled — putting money in the
     * day's takings nobody had handed over — or left untouched, losing the
     * record of what was actually collected.
     */
    public function isPartiallyPaid(): bool
    {
        return ! $this->isPaid() && $this->paidSoFarCents() > 0;
    }

    public function isActive(): bool
    {
        return in_array($this->status, config('orders.active_statuses'), true);
    }

    public function statusColor(): string
    {
        return config("orders.statuses.{$this->status}.color", 'gray');
    }
}
