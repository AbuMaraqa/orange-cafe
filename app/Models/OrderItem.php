<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    protected $fillable = [
        'product_id', 'menu_item_id', 'product_name', 'station', 'unit_price_cents', 'quantity', 'subtotal_cents', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'unit_price_cents' => 'integer',
            'quantity' => 'integer',
            'subtotal_cents' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function modifiers(): HasMany
    {
        return $this->hasMany(OrderItemModifier::class);
    }
}
