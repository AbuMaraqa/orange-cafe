<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreMenuItemRequest;
use App\Http\Requests\Admin\StoreMenuSectionRequest;
use App\Http\Requests\Admin\UpdateMenuItemRequest;
use App\Models\Category;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Product;
use App\Services\LanguageRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Drives the visual Menu -> Sections -> Products builder page: attaching
 * standalone products into a menu's sections (with an optional per-menu
 * price override), toggling their availability/featured flag, and
 * reordering both sections and the products within them. Section
 * creation/deletion lives here too since a section only really makes
 * sense in the context of the menu it belongs to.
 */
class MenuBuilderController extends Controller
{
    public function __construct(protected LanguageRegistry $registry)
    {
    }

    public function edit(Menu $menu): Response
    {
        $menu->load(['translations', 'categories.translations', 'categories.menuItems.product.translations']);

        $defaultLocale = $this->registry->default()?->code;

        $productIdsInMenu = $menu->categories->flatMap(fn (Category $section) => $section->menuItems->pluck('product_id'));
        $defaultLanguage = $this->registry->default();

        return Inertia::render('Admin/Menus/Builder', [
            'menu' => [
                'id' => $menu->id,
                'name' => $menu->translate($defaultLocale)?->name ?? $menu->slug,
            ],
            'defaultLocale' => $defaultLanguage ? [
                'code' => $defaultLanguage->code,
                'native_name' => $defaultLanguage->native_name,
            ] : null,
            'sections' => $menu->categories->map(fn (Category $section) => [
                'id' => $section->id,
                'name' => $section->translate($defaultLocale)?->name ?? $section->slug,
                'sort_order' => $section->sort_order,
                'items' => $section->menuItems->map(fn (MenuItem $item) => [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'name' => $item->product->translate($defaultLocale)?->name ?? $item->product->slug,
                    'image_url' => $item->product->image_url,
                    'base_price_cents' => $item->product->price_cents,
                    'price_cents' => $item->price_cents,
                    'is_active' => $item->is_active,
                    'is_featured' => $item->is_featured,
                    'sort_order' => $item->sort_order,
                ]),
            ]),
            'availableProducts' => Product::query()
                ->whereNotIn('id', $productIdsInMenu)
                ->orderBy('sort_order')
                ->get()
                ->map(fn (Product $product) => [
                    'id' => $product->id,
                    'name' => $product->translate($defaultLocale)?->name ?? $product->slug,
                    'price_cents' => $product->price_cents,
                    'image_url' => $product->image_url,
                ]),
        ]);
    }

    public function storeSection(StoreMenuSectionRequest $request, Menu $menu): RedirectResponse
    {
        $data = $request->validated();
        $translations = $data['translations'];
        unset($data['translations']);

        $maxSortOrder = $menu->categories()->max('sort_order');

        $menu->categories()->create([
            ...$data,
            ...$translations,
            'sort_order' => $maxSortOrder === null ? 0 : $maxSortOrder + 1,
        ]);

        return back()->with('success', __('admin.menus.section_created'));
    }

    public function destroySection(Category $section): RedirectResponse
    {
        $section->delete();

        return back()->with('success', __('admin.menus.section_deleted'));
    }

    public function reorderSections(Request $request, Menu $menu): RedirectResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array']])['ids'];

        foreach ($ids as $index => $id) {
            $menu->categories()->whereKey($id)->update(['sort_order' => $index]);
        }

        return back();
    }

    public function storeItem(StoreMenuItemRequest $request, Category $section): RedirectResponse
    {
        $data = $request->validated();
        $maxSortOrder = $section->menuItems()->max('sort_order');

        $section->menuItems()->create([
            ...$data,
            'menu_id' => $section->menu_id,
            'is_active' => true,
            'sort_order' => $maxSortOrder === null ? 0 : $maxSortOrder + 1,
        ]);

        return back()->with('success', __('admin.menus.item_added'));
    }

    public function updateItem(UpdateMenuItemRequest $request, MenuItem $item): RedirectResponse
    {
        $item->update($request->validated());

        return back()->with('success', __('admin.menus.item_updated'));
    }

    public function destroyItem(MenuItem $item): RedirectResponse
    {
        $item->delete();

        return back()->with('success', __('admin.menus.item_removed'));
    }

    public function reorderItems(Request $request, Category $section): RedirectResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array']])['ids'];

        foreach ($ids as $index => $id) {
            $section->menuItems()->whereKey($id)->update(['sort_order' => $index]);
        }

        return back();
    }
}
