<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\FillsMissingTranslations;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreMenuItemsRequest;
use App\Http\Requests\Admin\StoreMenuSectionRequest;
use App\Http\Requests\Admin\UpdateMenuItemRequest;
use App\Http\Requests\Admin\UpdateMenuSectionRequest;
use App\Models\Category;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Product;
use App\Models\Tag;
use App\Services\LanguageRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Drives the visual Menu -> Sections -> Products builder page: attaching
 * standalone products into a menu's sections (with an optional per-menu
 * price override), toggling their availability/featured flag, and
 * reordering both sections and the products within them. Section
 * creation/deletion lives here too since a section only really makes
 * sense in the context of the menu it belongs to.
 *
 * Sections nest to arbitrary depth, exactly as the customer menu renders
 * them (see MenuCatalogPresenter) and as the product form already lists
 * them. This page used to load only `$menu->categories` — top level
 * only — so a cafe that grouped "مشروبات <- ساخنة" could create the
 * sub-section from the categories screen and then never see it here, let
 * alone put a product in it.
 */
class MenuBuilderController extends Controller
{
    use FillsMissingTranslations;

    public function __construct(protected LanguageRegistry $registry) {}

    public function edit(Menu $menu): Response
    {
        $menu->load('translations');

        $defaultLocale = $this->registry->default()?->code;

        // Every section in this menu at any depth in one flat query, so
        // the item/product eager-loads below are a single pass rather than
        // one per level of nesting. The tree is rebuilt from parent_id in
        // presentSection().
        $sections = Category::query()
            ->where('menu_id', $menu->id)
            ->with([
                'translations',
                'menuItems' => fn ($q) => $q->orderBy('sort_order'),
                'menuItems.product.translations',
                'menuItems.product.tags.translations',
            ])
            ->get();

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
            'sections' => $sections
                ->where('parent_id', null)
                ->sortBy('sort_order')
                ->map(fn (Category $section) => $this->presentSection($section, $sections, $defaultLocale))
                ->values(),
            'availableProducts' => $this->availableProducts($defaultLocale),
            'tags' => Tag::query()
                ->with('translations')
                ->orderBy('sort_order')
                ->get()
                ->map(fn (Tag $tag) => [
                    'id' => $tag->id,
                    'name' => $tag->translate($defaultLocale)?->name ?? $tag->slug,
                    'icon' => $tag->icon,
                ]),
        ]);
    }

    /**
     * One section and everything under it. Sub-sections are resolved from
     * the flat collection already in memory rather than re-querying per
     * level, which keeps an arbitrarily deep tree at one query.
     *
     * @param  Collection<int, Category>  $all
     * @return array<string, mixed>
     */
    protected function presentSection(Category $section, Collection $all, ?string $defaultLocale): array
    {
        $children = $all
            ->where('parent_id', $section->id)
            ->sortBy('sort_order')
            ->map(fn (Category $child) => $this->presentSection($child, $all, $defaultLocale))
            ->values();

        $items = $section->menuItems->map(function (MenuItem $item) use ($section, $defaultLocale) {
            // effectiveStation() falls back to the section's own station
            // when the product has none. Handing it the section the item
            // was just read from stops that fallback re-fetching an
            // identical category row once per line.
            $item->setRelation('category', $section);

            return [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'name' => $item->product->translate($defaultLocale)?->name ?? $item->product->slug,
                'image_url' => $item->product->image_url,
                'base_price_cents' => $item->product->price_cents,
                'price_cents' => $item->price_cents,
                'is_active' => $item->is_active,
                'is_featured' => $item->is_featured,
                'sort_order' => $item->sort_order,
                // A line can be active here while the product itself is
                // switched off globally, in which case the customer menu
                // drops it (MenuCatalogPresenter filters on
                // product.is_active) with nothing on this screen to say so.
                'product_is_active' => $item->product->is_active,
                'prep_time_minutes' => $item->product->prep_time_minutes,
                'station' => $item->effectiveStation(),
                'tags' => $item->product->tags->map(fn (Tag $tag) => [
                    'id' => $tag->id,
                    'name' => $tag->translate($defaultLocale)?->name ?? $tag->slug,
                    'icon' => $tag->icon,
                ])->values(),
            ];
        })->values();

        return [
            'id' => $section->id,
            'parent_id' => $section->parent_id,
            'name' => $section->translate($defaultLocale)?->name ?? $section->slug,
            'is_active' => $section->is_active,
            'station' => $section->station,
            'sort_order' => $section->sort_order,
            'items' => $items,
            'children' => $children,
            // The whole subtree, so a collapsed parent still says how much
            // is filed under it.
            'total_item_count' => $items->count() + $children->sum(fn (array $c) => $c['total_item_count']),
        ];
    }

    /**
     * The full product catalog for the picker. Unlike before, products
     * already placed somewhere in this menu are *not* filtered out: the
     * uniqueness the schema and StoreMenuItemsRequest actually enforce is
     * per section, so a house special belongs in both "قهوة" and a
     * "الأكثر طلباً" section. The picker greys out only what is already
     * in the section being added to.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function availableProducts(?string $defaultLocale): Collection
    {
        return Product::query()
            ->with(['translations', 'tags.translations'])
            ->withCount('modifierGroups')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->translate($defaultLocale)?->name ?? $product->slug,
                'price_cents' => $product->price_cents,
                'image_url' => $product->image_url,
                'is_active' => $product->is_active,
                'prep_time_minutes' => $product->prep_time_minutes,
                'station' => $product->station,
                'modifier_group_count' => $product->modifier_groups_count,
                'tag_ids' => $product->tags->pluck('id')->values(),
                'tags' => $product->tags->map(fn (Tag $tag) => [
                    'id' => $tag->id,
                    'name' => $tag->translate($defaultLocale)?->name ?? $tag->slug,
                    'icon' => $tag->icon,
                ])->values(),
            ]);
    }

    public function storeSection(StoreMenuSectionRequest $request, Menu $menu): RedirectResponse
    {
        $data = $request->validated();
        $translations = $this->fillMissingTranslations($data['translations'], $this->registry->default()?->code);
        unset($data['translations']);

        // A sub-section is ordered among its siblings, not among the menu's
        // top-level sections.
        $maxSortOrder = Category::query()
            ->where('menu_id', $menu->id)
            ->where('parent_id', $data['parent_id'] ?? null)
            ->max('sort_order');

        Category::create([
            ...$data,
            ...$translations,
            'menu_id' => $menu->id,
            'sort_order' => $maxSortOrder === null ? 0 : $maxSortOrder + 1,
        ]);

        return back()->with('success', __('admin.menus.section_created'));
    }

    /**
     * Only the switches that make sense to flip while looking at the menu
     * as a whole — the section's names, image and slug stay on the
     * categories form.
     */
    public function updateSection(UpdateMenuSectionRequest $request, Category $section): RedirectResponse
    {
        $section->update($request->validated());

        return back()->with('success', __('admin.menus.section_updated'));
    }

    public function destroySection(Category $section): RedirectResponse
    {
        $section->delete();

        return back()->with('success', __('admin.menus.section_deleted'));
    }

    public function reorderSections(Request $request, Menu $menu): RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
            // Which level was dragged. Absent/null means the menu's
            // top-level sections.
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
        ]);

        foreach ($validated['ids'] as $index => $id) {
            Category::query()
                ->where('menu_id', $menu->id)
                ->where('parent_id', $validated['parent_id'] ?? null)
                ->whereKey($id)
                ->update(['sort_order' => $index]);
        }

        return back();
    }

    /**
     * Adds one or more products to a section in a single request. The
     * builder used to attach them one at a time through a dropdown of
     * every product in the database, which is a page reload per line and
     * unusable once a cafe has more than a screenful of products.
     */
    public function storeItems(StoreMenuItemsRequest $request, Category $section): RedirectResponse
    {
        $data = $request->validated();
        $maxSortOrder = $section->menuItems()->max('sort_order');
        $nextSortOrder = $maxSortOrder === null ? 0 : $maxSortOrder + 1;

        foreach ($data['product_ids'] as $productId) {
            $section->menuItems()->create([
                'product_id' => $productId,
                'menu_id' => $section->menu_id,
                // A price override only makes sense for a single line; a
                // bulk add takes each product's own price.
                'price_cents' => count($data['product_ids']) === 1
                    ? ($data['price_cents'] ?? null)
                    : null,
                'is_active' => true,
                'sort_order' => $nextSortOrder++,
            ]);
        }

        return back()->with('success', __('admin.menus.items_added', ['count' => count($data['product_ids'])]));
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
        $ids = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ])['ids'];

        foreach ($ids as $index => $id) {
            $section->menuItems()->whereKey($id)->update(['sort_order' => $index]);
        }

        return back();
    }
}
