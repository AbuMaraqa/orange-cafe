<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The junction between a Product and the Menu/Section it's placed in.
 * This is what lets the same Product appear in multiple menus at
 * different prices without duplicating the product record.
 */
class MenuItem extends Model
{
    protected $fillable = [
        'menu_id', 'category_id', 'product_id',
        'price_cents', 'is_active', 'is_featured', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The price actually charged for this product in this menu: its
     * override if one is set, otherwise the product's own default price.
     */
    public function effectivePriceCents(): int
    {
        return $this->price_cents ?? $this->product->price_cents;
    }

    /**
     * The printer station this line routes to: the product's own override
     * if set, otherwise the section (category) it's placed in here.
     */
    public function effectiveStation(): ?string
    {
        return $this->product->station ?? $this->category->station;
    }
}
