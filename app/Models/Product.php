<?php

namespace App\Models;

use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
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

    protected function imageUrl(): Attribute
    {
        return Attribute::make(
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

    /** The variation group, if this product is sold in sizes. */
    public function variationGroup(): ?ModifierGroup
    {
        return $this->modifierGroups->firstWhere('is_variation', true);
    }

    /**
     * What one of this product costs, given what the customer chose.
     *
     * The one place this arithmetic exists. It used to be a `+` written out
     * at every call site — the order writer, the customer's cart, the staff
     * order screen — which was fine while there was only one rule, and
     * became four places to forget the second one.
     *
     * The rule: a variation (small/medium/large) *is* the price, so it
     * replaces the base. Everything else (extra cheese) adds to it. A
     * product sold in sizes therefore needs no meaningful base price of its
     * own, which is the point — it never had a single price to give.
     *
     * @param  Collection<int, Modifier>  $selected
     * @param  int|null  $baseCents  the menu's price for this product, when
     *                               it differs from the product's own
     */
    public function unitPriceCents(Collection $selected, ?int $baseCents = null): int
    {
        $variationIds = $this->modifierGroups
            ->where('is_variation', true)
            ->pluck('id');

        $chosenVariation = $selected->first(
            fn (Modifier $modifier) => $variationIds->contains($modifier->modifier_group_id)
        );

        $base = $chosenVariation !== null
            ? $this->effectiveModifierPriceCents($chosenVariation)
            : ($baseCents ?? $this->price_cents);

        $extras = $selected
            ->reject(fn (Modifier $modifier) => $variationIds->contains($modifier->modifier_group_id))
            ->sum(fn (Modifier $modifier) => $this->effectiveModifierPriceCents($modifier));

        return $base + $extras;
    }

    /**
     * The price to advertise on the menu card.
     *
     * For a product sold in sizes this is the top of its range — see
     * ModifierGroup::topPriceCents(). Without it the card showed whatever
     * base price the product happened to carry, which for a sized dish is
     * either wrong or, if the cafe set it to zero so the sizes could stand
     * alone, advertised the dish as free.
     */
    public function displayPriceCents(?int $baseCents = null): int
    {
        $variation = $this->variationGroup();

        if ($variation === null) {
            return $baseCents ?? $this->price_cents;
        }

        $top = $variation->modifiers
            ->filter(fn (Modifier $modifier) => $this->isModifierActiveForProduct($modifier))
            ->map(fn (Modifier $modifier) => $this->effectiveModifierPriceCents($modifier))
            ->max();

        // A variation group with nothing active in it prices nothing, so
        // fall back rather than advertise zero — the exact outcome this
        // method exists to prevent.
        return $top ?? ($baseCents ?? $this->price_cents);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }
}
