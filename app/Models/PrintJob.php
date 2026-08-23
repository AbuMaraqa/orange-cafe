<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One piece of paper that should exist, and whether it does.
 *
 * The row outlives the queue entry that produced it. Laravel's `jobs` table
 * empties itself as work completes, so it could never answer the question
 * this system is built around — "which orders never printed?" — and that
 * question is the whole reason an order is saved independently of whether
 * any printer works.
 */
class PrintJob extends Model
{
    use HasFactory;

    /** Created, nothing attempted yet. */
    public const PENDING = 'pending';

    /** Claimed by a worker; bytes are on their way. */
    public const PRINTING = 'printing';

    /** Paper came out. Terminal — never retried automatically. */
    public const PRINTED = 'printed';

    /** Attempts exhausted for a reason other than the printer being down. */
    public const FAILED = 'failed';

    /** The printer could not be reached. Distinct from FAILED so the screen
     *  can tell "fix the printer" apart from "something else went wrong". */
    public const PRINTER_OFFLINE = 'printer_offline';

    /**
     * Somebody looked at this and decided it is not wanted.
     *
     * Not a delete, on purpose. The row is the only record that the ticket
     * was ever owed: erase it and "did the kitchen get table 6's order?"
     * has no answer at all, which is worse than an answer nobody likes.
     * Cancelling leaves the evidence — the attempts, the printer that was
     * down, the error it gave — and only takes it off the list of things
     * still waiting on a person.
     *
     * Reversible: a cancelled job still has a printer and has not printed,
     * so the reprint button keeps working on it. Cancelling by mistake
     * costs one more tap, not a lost ticket.
     */
    public const CANCELLED = 'cancelled';

    public const KIND_KITCHEN = 'kitchen';

    public const KIND_CASHIER = 'cashier';

    /**
     * Three, then stop.
     *
     * The old behaviour retried every 30 seconds for a full day. For a
     * printer that is switched off overnight that is 2,880 attempts, and
     * the ticket that eventually emerges belongs to an order served
     * yesterday. Giving up quickly and leaving the row visible for a human
     * to reprint is both cheaper and more honest.
     */
    public const MAX_ATTEMPTS = 3;

    protected $fillable = [
        'reference', 'order_id', 'printer_id', 'station', 'printer_name',
        'kind', 'status', 'attempts', 'last_attempt_at', 'printed_at',
        'last_error', 'items_count', 'printed_on_printer_id', 'requested_by',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'items_count' => 'integer',
            'last_attempt_at' => 'datetime',
            'printed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }

    /** Where the paper actually came out, when it was not the intended one. */
    public function printedOnPrinter(): BelongsTo
    {
        return $this->belongsTo(Printer::class, 'printed_on_printer_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isPrinted(): bool
    {
        return $this->status === self::PRINTED;
    }

    /** Everything a human might still want on paper. */
    public function scopeUnprinted(Builder $query): Builder
    {
        return $query->whereIn('status', [self::PENDING, self::FAILED, self::PRINTER_OFFLINE]);
    }

    /**
     * Only what a person can act on: a job still being attempted is not
     * something to offer a reprint button for.
     */
    public function scopeNeedsAttention(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [self::FAILED, self::PRINTER_OFFLINE])
            // A ticket held after a printer recovery sits at PENDING with
            // the reason on it, and PENDING was excluded here — so the one
            // outcome that needs a person (nothing will ever print this
            // unless you ask) was the one nothing reported. Tickets piled
            // up held, the alert stayed quiet, the printers screen said
            // "online", and the kitchen simply never heard about the food.
            //
            // A freshly queued job is also PENDING but has no error yet,
            // which is exactly what tells the two apart.
            ->orWhere(fn (Builder $held) => $held
                ->where('status', self::PENDING)
                ->whereNotNull('last_error'));
    }

    public function scopeForOrder(Builder $query, int $orderId): Builder
    {
        return $query->where('order_id', $orderId);
    }

    /**
     * The dedup key for a piece of paper.
     *
     * Derived, never generated: the same ticket must resolve to the same
     * key however it is asked for — a repeat click, a lifecycle hook firing
     * twice, a worker that printed and died before recording it. `$attempt`
     * is what a deliberate reprint bumps, so a person asking for a second
     * copy gets one while accidents do not.
     */
    public static function keyFor(int $orderId, int $printerId, string $station, string $kind, int $copy = 1, ?string $scope = null): string
    {
        $key = "{$kind}:{$orderId}:{$printerId}:{$station}:{$copy}";

        // A round added to a table that has already printed is a different
        // piece of paper from the ticket that printed earlier, even though
        // the order, printer, station and kind are all identical. Without
        // something to tell them apart the key matched the settled job and
        // reserve() handed back null: adding items to a confirmed order
        // queued nothing and printed nothing, silently, and the kitchen
        // simply never heard about the second round.
        return $scope === null ? $key : "{$key}:{$scope}";
    }
}
