<?php

namespace App\Models;

use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model implements TranslatableContract
{
    use HasFactory, Translatable;

    public $translatedAttributes = ['name', 'description'];

    protected $fillable = ['menu_id', 'parent_id', 'slug', 'station', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * The menu (Main Menu, Ramadan Menu, ...) this section belongs to. A
     * section belongs to exactly one menu; subcategories share their
     * parent's menu.
     */
    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /**
     * Subcategories, i.e. the "submenu" under a top-level menu category.
     */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id')->orderBy('sort_order');
    }

    public function menuItems(): HasMany
    {
        return $this->hasMany(MenuItem::class)->orderBy('sort_order');
    }

    /**
     * Products placed in this section, via menu_items — a product is never
     * owned by a section directly, so it can appear in several sections
     * (across different menus) at once.
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'menu_items')
            ->withPivot(['id', 'menu_id', 'price_cents', 'is_active', 'is_featured', 'sort_order'])
            ->orderByPivot('sort_order');
    }

    public function isTopLevel(): bool
    {
        return $this->parent_id === null;
    }
}
