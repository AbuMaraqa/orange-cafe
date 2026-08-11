<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Order extends Model
{
    protected $fillable = [
        'table_id', 'guest_count', 'table_number', 'customer_name',
        'status', 'locale', 'notes', 'total_cents', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'guest_count' => 'integer',
            'total_cents' => 'integer',
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
            $reference = 'ORD-'.now()->format('ymd').'-'.strtoupper(Str::random(4));
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

    public function isPaid(): bool
    {
        return $this->paid_at !== null;
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
