<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\Tag;
use Illuminate\Support\Collection;

/**
 * Builds the "visible menus -> sections -> products" payload shared by the
 * public customer menu and the staff-facing manual order screen, so both
 * present exactly the same catalog (and the same resolved pricing) without
 * duplicating the eager-load/mapping logic.
 */
class MenuCatalogPresenter
{
    public function __construct(protected MenuResolverService $resolver)
    {
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function visibleMenusPayload(): Collection
    {
        $menus = $this->resolver->visibleMenus();

        $menus->load([
            'translations',
            'categories' => fn ($q) => $q->where('is_active', true),
            'categories.translations',
            'categories.children' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order'),
            'categories.children.translations',
            'categories.children.menuItems' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order'),
            'categories.children.menuItems.product' => fn ($q) => $q->where('is_active', true),
            'categories.children.menuItems.product.translations',
            'categories.children.menuItems.product.tags.translations',
            'categories.children.menuItems.product.modifierGroups' => fn ($q) => $q->where('is_active', true),
            'categories.children.menuItems.product.modifierGroups.translations',
            'categories.children.menuItems.product.modifierGroups.modifiers' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order'),
            'categories.children.menuItems.product.modifierGroups.modifiers.translations',
            'categories.children.menuItems.product.modifierOverrides',
            'categories.menuItems' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order'),
            'categories.menuItems.product' => fn ($q) => $q->where('is_active', true),
            'categories.menuItems.product.translations',
            'categories.menuItems.product.tags.translations',
            'categories.menuItems.product.modifierGroups' => fn ($q) => $q->where('is_active', true),
            'categories.menuItems.product.modifierGroups.translations',
            'categories.menuItems.product.modifierGroups.modifiers' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order'),
            'categories.menuItems.product.modifierGroups.modifiers.translations',
            'categories.menuItems.product.modifierOverrides',
        ]);

        return $menus->map(fn (Menu $menu) => [
            'id' => $menu->id,
            'name' => $menu->name,
            'categories' => $menu->categories->map(fn (Category $category) => $this->presentCategory($category)),
        ])->values();
    }

    protected function presentCategory(Category $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'description' => $category->description,
            'image_url' => $category->image_url,
            'subcategories' => $category->children->map(fn (Category $child) => [
                'id' => $child->id,
                'name' => $child->name,
                'description' => $child->description,
                'image_url' => $child->image_url,
                'products' => $child->menuItems
                    ->filter(fn (MenuItem $item) => $item->product !== null)
                    ->map(fn (MenuItem $item) => $this->presentMenuItem($item))
                    ->values(),
            ]),
            'products' => $category->children->isEmpty()
                ? $category->menuItems
                    ->filter(fn (MenuItem $item) => $item->product !== null)
                    ->map(fn (MenuItem $item) => $this->presentMenuItem($item))
                    ->values()
                : [],
        ];
    }

    protected function presentMenuItem(MenuItem $item): array
    {
        $product = $item->product;

        return [
            'id' => $product->id,
            'menu_item_id' => $item->id,
            'name' => $product->name,
            'description' => $product->description,
            'price_cents' => $item->effectivePriceCents(),
            'is_featured' => $item->is_featured,
            'prep_time_minutes' => $product->prep_time_minutes,
            'image_url' => $product->image_url,
            'tags' => $product->tags->map(fn (Tag $tag) => ['id' => $tag->id, 'name' => $tag->name, 'icon' => $tag->icon]),
            'modifier_groups' => $product->modifierGroups->map(fn (ModifierGroup $group) => [
                'id' => $group->id,
                'name' => $group->name,
                'is_required' => $group->is_required,
                'min_select' => $group->min_select,
                'max_select' => $group->max_select,
                // Modifiers are shared across products by default, but this
                // product may override a modifier's price or hide it via
                // Product::modifierOverrides (see effectiveModifierPriceCents).
                'modifiers' => $group->modifiers
                    ->filter(fn ($modifier) => $product->isModifierActiveForProduct($modifier))
                    ->map(fn ($modifier) => [
                        'id' => $modifier->id,
                        'name' => $modifier->name,
                        'price_cents' => $product->effectiveModifierPriceCents($modifier),
                    ])->values(),
            ]),
        ];
    }
}
