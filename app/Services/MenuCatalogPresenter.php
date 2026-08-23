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
    public function __construct(protected MenuResolverService $resolver) {}

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
            'categories.activeChildrenRecursive',
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

    /**
     * One category and everything under it, to whatever depth it goes.
     *
     * Recursive rather than the two fixed levels this used to build: a cafe
     * that groups "مشروبات ← ساخنة ← قهوة" had its third level silently
     * dropped — the products under it belonged to a category the menu never
     * rendered, so they existed in the admin panel and were unorderable.
     *
     * A section carries its own products whether or not it also has
     * sub-sections. It used to drop them the moment a child existed, so a
     * cafe with "مشروبات" holding both a couple of house specials and a
     * "ساخنة" sub-section lost the specials entirely — they were orderable
     * in the admin panel and invisible to every customer.
     *
     * `product_count` is the whole subtree, which is what a customer is
     * really asking when they look at a category tile: is there anything in
     * there worth opening?
     */
    protected function presentCategory(Category $category): array
    {
        $children = $category->relationLoaded('activeChildrenRecursive')
            ? $category->activeChildrenRecursive
            : $category->children;

        // Filtered again here, and not only in the relation.
        //
        // The fallback branch above loads children unfiltered, and a
        // relation can arrive already loaded by a caller that knew nothing
        // about this rule. "Off means invisible" is worth more than one
        // guarantee: the query is the fast path, this is the one that
        // actually holds.
        $subcategories = $children
            ->filter(fn (Category $child) => $child->is_active)
            ->map(fn (Category $child) => $this->presentCategory($child))
            ->values();

        // Cheapest first, within each section.
        //
        // Sorted here rather than in the eager-load's `orderBy` because the
        // price a customer is shown is not a column: `menu_items.price_cents`
        // is an optional per-menu override of the product's own price (see
        // MenuItem::effectivePriceCents), so a database sort on either one
        // alone puts overridden items in the wrong place. presentMenuItem()
        // has already resolved the real figure, so this sorts on that.
        //
        // PHP's sorts are stable, so items sharing a price keep the
        // sort_order the admin arranged them in.
        // Both switches, checked on the objects themselves.
        //
        // `product !== null` alone was doing this job, and only by accident:
        // it works when the product was eager-loaded *with* the is_active
        // constraint, because an excluded product comes back null. Reached
        // any other way — a lazy load, a caller that loaded the tree itself
        // — the product is simply there, switched off, and priced on the
        // menu. Reading the flags is the same cost and does not depend on
        // how the row arrived.
        $products = $category->menuItems
            ->filter(fn (MenuItem $item) => $item->is_active
                && $item->product !== null
                && $item->product->is_active)
            ->map(fn (MenuItem $item) => $this->presentMenuItem($item))
            ->sortBy('price_cents')
            ->values();

        return [
            'id' => $category->id,
            'name' => $category->name,
            'description' => $category->description,
            'image_url' => $category->image_url,
            'subcategories' => $subcategories,
            'products' => $products,
            'product_count' => $products->count()
                + $subcategories->sum(fn (array $child) => $child['product_count']),
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
            // For a dish sold in sizes this is the top of its range rather
            // than a base price, because a sized dish has no single price —
            // see Product::displayPriceCents.
            'price_cents' => $product->displayPriceCents($item->effectivePriceCents()),
            // What the base would be if nothing were chosen. The client
            // needs it to price an unsized dish, and must not use it for a
            // sized one.
            'base_price_cents' => $item->effectivePriceCents(),
            'is_featured' => $item->is_featured,
            'prep_time_minutes' => $product->prep_time_minutes,
            'image_url' => $product->image_url,
            'tags' => $product->tags->map(fn (Tag $tag) => ['id' => $tag->id, 'name' => $tag->name, 'icon' => $tag->icon]),
            'modifier_groups' => $product->modifierGroups->map(fn (ModifierGroup $group) => [
                'id' => $group->id,
                'name' => $group->name,
                'is_required' => $group->is_required,
                // The chosen option replaces the price rather than adding
                // to it. The client has to know which group that is, or it
                // recomputes the basket the old way and shows base + size.
                'is_variation' => $group->is_variation,
                'min_select' => $group->min_select,
                'max_select' => $group->max_select,
                // Modifiers are shared across products by default, but this
                // product may override a modifier's price or hide it via
                // Product::modifierOverrides (see effectiveModifierPriceCents).
                'modifiers' => $group->modifiers
                    ->filter(fn ($modifier) => $product->isModifierActiveForProduct($modifier))
                    ->map(fn ($modifier) => [
                        'id' => $modifier->id,
                        // Carried on the modifier itself so a chosen option
                        // still knows which group it came from once it has
                        // been detached into a cart line — that is what
                        // tells a size from an extra when repricing.
                        'modifier_group_id' => $group->id,
                        'name' => $modifier->name,
                        'price_cents' => $product->effectiveModifierPriceCents($modifier),
                    ])->values(),
            ]),
        ];
    }
}
