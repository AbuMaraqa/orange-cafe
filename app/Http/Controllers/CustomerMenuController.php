<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\Tag;
use Inertia\Inertia;
use Inertia\Response;

class CustomerMenuController extends Controller
{
    /**
     * Public QR-menu page. Locale comes from the URL segment (e.g. /ar/menu,
     * /en/menu) like every other page in the app, so it's bookmarkable and
     * shareable per language — Astrotomic resolves each category/product's
     * translation from the app locale automatically, falling back to the
     * default language when a translation is missing.
     */
    public function index(): Response
    {
        $categories = Category::query()
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->with([
                'translations',
                'children' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order'),
                'children.translations',
                'children.products' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order'),
                'children.products.translations',
                'children.products.tags.translations',
                'children.products.modifierGroups' => fn ($q) => $q->where('is_active', true),
                'children.products.modifierGroups.translations',
                'children.products.modifierGroups.modifiers' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order'),
                'children.products.modifierGroups.modifiers.translations',
                'products' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order'),
                'products.translations',
                'products.tags.translations',
                'products.modifierGroups' => fn ($q) => $q->where('is_active', true),
                'products.modifierGroups.translations',
                'products.modifierGroups.modifiers' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order'),
                'products.modifierGroups.modifiers.translations',
            ])
            ->orderBy('sort_order')
            ->get();

        return Inertia::render('Customer/Menu', [
            'categories' => $categories->map(fn (Category $category) => $this->presentCategory($category)),
            'tags' => Tag::query()->where('is_active', true)->orderBy('sort_order')->get()
                ->map(fn (Tag $tag) => ['id' => $tag->id, 'name' => $tag->name, 'icon' => $tag->icon]),
        ]);
    }

    protected function presentCategory(Category $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'description' => $category->description,
            'subcategories' => $category->children->map(fn (Category $child) => [
                'id' => $child->id,
                'name' => $child->name,
                'description' => $child->description,
                'products' => $child->products->map(fn (Product $product) => $this->presentProduct($product)),
            ]),
            'products' => $category->children->isEmpty()
                ? $category->products->map(fn (Product $product) => $this->presentProduct($product))
                : [],
        ];
    }

    protected function presentProduct(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'description' => $product->description,
            'price_cents' => $product->price_cents,
            'image_url' => $product->image_url,
            'tags' => $product->tags->map(fn (Tag $tag) => ['id' => $tag->id, 'name' => $tag->name, 'icon' => $tag->icon]),
            'modifier_groups' => $product->modifierGroups->map(fn (ModifierGroup $group) => [
                'id' => $group->id,
                'name' => $group->name,
                'is_required' => $group->is_required,
                'min_select' => $group->min_select,
                'max_select' => $group->max_select,
                'modifiers' => $group->modifiers->map(fn ($modifier) => [
                    'id' => $modifier->id,
                    'name' => $modifier->name,
                    'price_cents' => $modifier->price_cents,
                ]),
            ]),
        ];
    }
}
