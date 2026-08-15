<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'table_id', 'guest_count', 'table_number', 'customer_name',
        'status', 'locale', 'notes', 'total_cents', 'created_by', 'paid_at', 'paid_by', 'amount_paid_cents',
    ];

    protected function casts(): array
    {
        return [
            'guest_count' => 'integer',
            'total_cents' => 'integer',
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
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

    public function isActive(): bool
    {
        return in_array($this->status, config('orders.active_statuses'), true);
    }

    public function statusColor(): string
    {
        return config("orders.statuses.{$this->status}.color", 'gray');
    }
}
