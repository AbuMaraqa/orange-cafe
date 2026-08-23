<?php

namespace App\Models;

use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Category extends Model implements TranslatableContract
{
    use HasFactory, Translatable;

    public $translatedAttributes = ['name', 'description'];

    protected $fillable = ['menu_id', 'parent_id', 'slug', 'station', 'image_path', 'is_active', 'sort_order'];

    protected $appends = ['image_url'];

    protected function casts(): array
    {
        return [
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

    /**
     * children, their children, and so on to whatever depth exists.
     *
     * Laravel has no built-in way to eager-load a self-referencing tree, so
     * the relation loads itself: each level pulls the next, and the
     * recursion ends naturally at a category with no children. Without this
     * the menu could only ever show two levels, however deep the cafe
     * actually nested its sections.
     */
    public function childrenRecursive(): HasMany
    {
        return $this->children()->with(['childrenRecursive', 'translations']);
    }

    /**
     * The same tree, as a customer is allowed to see it.
     *
     * childrenRecursive above deliberately shows everything — the admin's
     * section tree has to list a switched-off section, or there would be no
     * way to switch it back on. The customer menu needs the opposite, and
     * used the admin's relation: a section turned off still appeared, and so
     * did every item under it, including items whose product had been
     * switched off. Turning something off in the admin panel changed nothing
     * a customer could see, which is the one thing that switch is for.
     *
     * The filters travel *inside* the relation rather than sitting on the
     * eager-load in MenuCatalogPresenter, because the recursion happens here.
     * A constraint applied at the call site reaches the first level of
     * children and no further, which is exactly how the gap opened: the top
     * of the menu was filtered and the depths of it were not.
     *
     * The item loads come along for the same reason — nested categories
     * would otherwise lazy-load theirs one category at a time, unfiltered.
     */
    public function activeChildrenRecursive(): HasMany
    {
        return $this->children()
            ->where('is_active', true)
            ->with([
                'activeChildrenRecursive',
                'translations',
                'menuItems' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order'),
                'menuItems.product' => fn ($q) => $q->where('is_active', true),
                'menuItems.product.translations',
                'menuItems.product.tags.translations',
                'menuItems.product.modifierGroups' => fn ($q) => $q->where('is_active', true),
                'menuItems.product.modifierGroups.translations',
                'menuItems.product.modifierGroups.modifiers' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order'),
                'menuItems.product.modifierGroups.modifiers.translations',
                'menuItems.product.modifierOverrides',
            ]);
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

    /**
     * IDs of every descendant at any depth (children, grandchildren, ...).
     * Categories nest to arbitrary depth via parent_id with no materialized
     * path, so this walks the tree level by level — used to stop a
     * category from being reparented under its own descendant, which
     * would create a cycle.
     */
    public function descendantIds(): array
    {
        $ids = [];
        $frontier = [$this->id];

        while ($frontier !== []) {
            $childIds = static::query()->whereIn('parent_id', $frontier)->pluck('id')->all();
            $ids = [...$ids, ...$childIds];
            $frontier = $childIds;
        }

        return $ids;
    }
}
