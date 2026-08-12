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
    protected $fillable = ['slug', 'station', 'image_path', 'price_cents', 'is_active', 'sort_order'];

    protected $appends = ['image_url'];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
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

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }
}
