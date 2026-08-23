<?php

namespace App\Models;

use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ModifierGroup extends Model implements TranslatableContract
{
    use HasFactory, Translatable;

    public $translatedAttributes = ['name'];

    protected $fillable = ['is_required', 'is_variation', 'min_select', 'max_select', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'is_variation' => 'boolean',
            'min_select' => 'integer',
            'max_select' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * The most a customer can pay for this variation, which is the figure
     * the menu card advertises.
     *
     * The highest and not the lowest on purpose: the card is a promise
     * about what the dish costs, and a promise that only holds for the
     * smallest size is one the basket then breaks. Quoting the top of the
     * range means every choice a customer makes from here moves the price
     * down.
     */
    public function topPriceCents(): int
    {
        return (int) $this->modifiers->max('price_cents');
    }

    public function modifiers(): HasMany
    {
        return $this->hasMany(Modifier::class)->orderBy('sort_order');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_modifier_group')->withPivot('sort_order');
    }
}
