<?php

namespace App\Models;

use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Product extends Model implements TranslatableContract
{
    use HasFactory, Translatable;

    public $translatedAttributes = ['name', 'description'];

    // Deliberately no category_id/menu_id here — a product is a standalone
    // entity, attached to whichever menus/sections it belongs to via
    // menu_items (possibly several at once, each with its own price).
    protected $fillable = ['slug', 'station', 'image_path', 'price_cents', 'prep_time_minutes', 'is_active', 'sort_order'];

    protected $appends = ['image_url'];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'prep_time_minutes' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected function imageUrl(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: fn () => $this->image_path ? Storage::disk('public')->url($this->image_path) : null,
        );
    }

    public function menuItems(): HasMany
    {
        return $this->hasMany(MenuItem::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'menu_items')
            ->withPivot(['id', 'menu_id', 'price_cents', 'is_active', 'is_featured', 'sort_order']);
    }

    public function menus(): BelongsToMany
    {
        return $this->belongsToMany(Menu::class, 'menu_items')
            ->withPivot(['id', 'category_id', 'price_cents', 'is_active', 'is_featured', 'sort_order']);
    }

    public function modifierGroups(): BelongsToMany
    {
        return $this->belongsToMany(ModifierGroup::class, 'product_modifier_group')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }

    public function modifierOverrides(): HasMany
    {
        return $this->hasMany(ProductModifierOverride::class);
    }

    /**
     * A modifier's price/availability is shared across every product that
     * uses its group by default, but a single product can override either
     * one (e.g. "Large" costs more on a chicken dish than on a juice) via
     * modifierOverrides — assumes that relation is already eager-loaded.
     */
    public function effectiveModifierPriceCents(Modifier $modifier): int
    {
        $override = $this->modifierOverrides->firstWhere('modifier_id', $modifier->id);

        return $override?->price_cents ?? $modifier->price_cents;
    }

    public function isModifierActiveForProduct(Modifier $modifier): bool
    {
        $override = $this->modifierOverrides->firstWhere('modifier_id', $modifier->id);
        $isActive = $override?->is_active;

        return $isActive ?? $modifier->is_active;
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }
}
