<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\FillsMissingTranslations;
use App\Http\Controllers\Controller;
use App\Services\Images\ImageOptimizer;
use App\Http\Controllers\Concerns\PresentsPagination;
use App\Http\Requests\Admin\StoreProductRequest;
use App\Http\Requests\Admin\UpdateProductRequest;
use App\Models\Category;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Printer;
use App\Models\Product;
use App\Models\Tag;
use App\Services\LanguageRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    use PresentsPagination;

    use FillsMissingTranslations;

    public function __construct(protected LanguageRegistry $registry) {}

    /**
     * Flips one product on or off straight from the list.
     *
     * Taking a product off the menu is the most time-critical edit in this
     * app — the kitchen runs out of something mid-service and it has to stop
     * being orderable now, not after a round trip through the edit form,
     * which also risks saving whatever else was half-changed on that screen.
     *
     * The new state is derived here rather than accepted from the request:
     * two waiters tapping the same row would otherwise both send
     * "is_active: false" and the second tap would be a no-op instead of
     * turning it back on.
     */
    /**
     * Persists a drag-and-drop reorder of the product list.
     *
     * Guarded against being handed a filtered list. The table can be showing
     * any subset of the catalog, and numbering a subset from zero would
     * silently renumber those products *past* everything the filter hid —
     * dragging inside a search for "عصير" would quietly move all four juices
     * to the top of the whole catalog. The client hides the drag handles
     * while a filter is on; this refuses the request even if it arrives
     * anyway, because a wrong sort_order is invisible until a customer sees
     * the menu in the wrong order.
     */
    public function reorder(Request $request): RedirectResponse
    {
        $order = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['integer', 'exists:products,id'],
        ])['order'];

        if (count($order) !== Product::query()->count()) {
            return back()->withErrors([
                'order' => __('admin.products.reorder_needs_full_list'),
            ]);
        }

        DB::transaction(function () use ($order) {
            foreach ($order as $index => $id) {
                Product::whereKey($id)->update(['sort_order' => $index]);
            }
        });

        return back();
    }

    public function toggleActive(Product $product): RedirectResponse
    {
        $product->update(['is_active' => ! $product->is_active]);

        return back();
    }

    /**
     * The product list, narrowed by whatever the filter bar is asking for.
     *
     * Filtering happens in SQL rather than in the browser. The catalog is
     * 148 products and growing, each carrying its translations, its tags and
     * every placement it has — shipping all of that to the client so it
     * could be filtered there made this the slowest screen in the panel, and
     * "find the product" is the single most common thing done on it.
     */
    public function index(Request $request): Response
    {
        $activeCodes = $this->registry->codes();
        $filters = $this->productFilters($request);

        $products = Product::query()
            ->with([
                'translations',
                'tags',
                'menus.translations',
                // For the placements column: one row per section the product
                // sits in, each needing its own name and its menu's. Eager
                // loaded because 148 products would otherwise be 148 queries.
                'menuItems.menu.translations',
                'menuItems.category.translations',
            ])
            ->when($filters['search'], function ($query, string $search) {
                // Every locale's name, not just the default one: the panel
                // runs in Arabic but a good part of this catalog was typed in
                // from an English price list, and a product you can only find
                // under the name you did not use is not findable. The slug is
                // in because it is what shows in URLs and in Menu Builder's
                // chips, so it is what gets copied and pasted around.
                $query->where(function ($query) use ($search) {
                    $query
                        ->whereHas('translations', fn ($translation) => $translation->where('name', 'like', "%{$search}%"))
                        ->orWhere('slug', 'like', "%{$search}%");
                });
            })
            ->when($filters['menu_id'], fn ($query, $id) => $query->whereHas('menuItems', fn ($item) => $item->where('menu_id', $id)))
            ->when($filters['category_id'], fn ($query, $id) => $query->whereHas('menuItems', fn ($item) => $item->where('category_id', $id)))
            ->when($filters['tag_id'], fn ($query, $id) => $query->whereHas('tags', fn ($tag) => $tag->where('tags.id', $id)))
            ->when($filters['station'], fn ($query, $station) => $query->where('station', $station))
            ->when($filters['status'], fn ($query, $status) => $query->where('is_active', $status === 'active'))
            // "Not on any menu" is the one an admin cannot reach any other
            // way: a product with no placement is invisible to customers and
            // absent from Menu Builder, so it can sit there wrong for months.
            ->when($filters['placement'] === 'placed', fn ($query) => $query->has('menuItems'))
            ->when($filters['placement'] === 'unplaced', fn ($query) => $query->doesntHave('menuItems'))
            // Counting rows in *active* locales only, so switching a language
            // off does not make every product look complete on the strength
            // of translations nobody can see any more.
            ->when($filters['translations'] === 'missing', fn ($query) => $query->whereHas(
                'translations',
                fn ($translation) => $translation->whereIn('locale', $activeCodes),
                '<',
                count($activeCodes),
            ))
            ->orderBy('sort_order')
            ->orderBy('id');

        return Inertia::render('Admin/Products/Index', [
            // paginated() applies withQueryString(), which is what keeps a
            // filtered view filtered on page two.
            ...$this->paginated('products', $products, $request, fn (Product $product) => $this->present($product)),
            'languages' => $this->registry->active()->values(),
            'filters' => $filters,
            'filterOptions' => [
                'menus' => $this->menuOptions(),
                'categories' => $this->categoryOptions(),
                'tags' => $this->tagOptions(),
                'stations' => $this->printerOptions(),
            ],
            // So the bar can say "37 of 148" instead of only how many came
            // back: without the total, a filter matching nothing looks
            // exactly like an empty catalog.
            'totalCount' => Product::query()->count(),
        ]);
    }

    /**
     * Reads the filter bar's state off the query string, discarding anything
     * that is not a value the bar itself can produce.
     *
     * Handed back to the page whole, because the bar is rendered from these:
     * a filter arriving in a bookmarked or shared URL has to show up in the
     * controls, not only in the results.
     *
     * @return array<string, mixed>
     */
    protected function productFilters(Request $request): array
    {
        $oneOf = fn (string $key, array $allowed) => in_array($request->input($key), $allowed, true)
            ? $request->input($key)
            : null;

        $id = fn (string $key) => $request->filled($key) ? (int) $request->input($key) : null;

        return [
            'search' => trim((string) $request->input('search', '')),
            'menu_id' => $id('menu_id'),
            'category_id' => $id('category_id'),
            'tag_id' => $id('tag_id'),
            'station' => $request->input('station') ?: null,
            'status' => $oneOf('status', ['active', 'inactive']),
            'placement' => $oneOf('placement', ['placed', 'unplaced']),
            'translations' => $oneOf('translations', ['missing']),
        ];
    }

    protected function menuOptions()
    {
        $defaultLocale = $this->registry->default()?->code;

        return Menu::query()
            ->with('translations')
            ->orderBy('id')
            ->get()
            ->map(fn (Menu $menu) => [
                'id' => $menu->id,
                'name' => $menu->translate($defaultLocale)?->name ?? $menu->slug,
            ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Products/Form', [
            'languages' => $this->registry->active()->values(),
            'modifierGroups' => $this->modifierGroupOptions(),
            'tags' => $this->tagOptions(),
            'categories' => $this->categoryOptions(),
            'printerOptions' => $this->printerOptions(),
            'product' => null,
        ]);
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $translations = $this->fillMissingTranslations($data['translations'], $this->registry->default()?->code);
        $modifierGroupIds = $data['modifier_group_ids'] ?? [];
        $modifierOverrides = $data['modifier_overrides'] ?? [];
        $tagIds = $data['tag_ids'] ?? [];
        $categoryIds = $data['category_ids'] ?? [];
        unset($data['translations'], $data['modifier_group_ids'], $data['modifier_overrides'], $data['tag_ids'], $data['category_ids']);

        if ($request->hasFile('image')) {
            $data['image_path'] = app(ImageOptimizer::class)->store($request->file('image'), 'products');
        }
        unset($data['image']);

        $product = DB::transaction(function () use ($data, $translations, $modifierGroupIds, $modifierOverrides, $tagIds) {
            $product = Product::create([...$data, ...$translations]);
            $product->modifierGroups()->sync($modifierGroupIds);
            $product->tags()->sync($tagIds);
            $this->syncModifierOverrides($product, $modifierOverrides);

            return $product;
        });

        $this->syncCategories($product, $categoryIds);

        return to_route('admin.products.index')->with('success', __('admin.products.created'));
    }

    public function edit(Product $product): Response
    {
        $product->load('translations', 'modifierGroups', 'modifierOverrides', 'tags', 'menus.translations', 'categories');

        return Inertia::render('Admin/Products/Form', [
            'languages' => $this->registry->active()->values(),
            'modifierGroups' => $this->modifierGroupOptions(),
            'tags' => $this->tagOptions(),
            'categories' => $this->categoryOptions(),
            'printerOptions' => $this->printerOptions(),
            'product' => $this->present($product, includeTranslations: true),
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        $data = $request->validated();
        $translations = $this->fillMissingTranslations($data['translations'], $this->registry->default()?->code);
        $modifierGroupIds = $data['modifier_group_ids'] ?? [];
        $modifierOverrides = $data['modifier_overrides'] ?? [];
        $tagIds = $data['tag_ids'] ?? [];
        $categoryIds = $data['category_ids'] ?? [];
        unset($data['translations'], $data['modifier_group_ids'], $data['modifier_overrides'], $data['tag_ids'], $data['category_ids']);

        if ($request->hasFile('image')) {
            if ($product->image_path) {
                Storage::disk('public')->delete($product->image_path);
            }
            $data['image_path'] = app(ImageOptimizer::class)->store($request->file('image'), 'products');
        }
        unset($data['image']);

        DB::transaction(function () use ($data, $translations, $product, $modifierGroupIds, $modifierOverrides, $tagIds) {
            $product->update([...$data, ...$translations]);
            $product->modifierGroups()->sync($modifierGroupIds);
            $product->tags()->sync($tagIds);
            $this->syncModifierOverrides($product, $modifierOverrides);
        });

        $this->syncCategories($product, $categoryIds);

        return to_route('admin.products.index')->with('success', __('admin.products.updated'));
    }

    /**
     * Replaces this product's modifier price/availability overrides with
     * the submitted set. Entries where both fields are null are dropped —
     * they mean "inherit the modifier's global value", so there's nothing
     * to store for them (see ProductModifierOverride).
     *
     * @param  array<int, array{modifier_id:int, price_cents?:?int, is_active?:?bool}>  $overrides
     */
    protected function syncModifierOverrides(Product $product, array $overrides): void
    {
        $product->modifierOverrides()->delete();

        foreach ($overrides as $override) {
            if (($override['price_cents'] ?? null) === null && ($override['is_active'] ?? null) === null) {
                continue;
            }

            $product->modifierOverrides()->create([
                'modifier_id' => $override['modifier_id'],
                'price_cents' => $override['price_cents'] ?? null,
                'is_active' => $override['is_active'] ?? null,
            ]);
        }
    }

    /**
     * Files the product under exactly the given categories, adding the
     * MenuItem links it is missing and dropping the ones it should no
     * longer have.
     *
     * This used to only ever add. A product filed under the wrong section
     * could not be moved out of it from here — the only way was to go and
     * find it in Menu Builder — so in practice it got added to the right
     * section as well and appeared twice on the menu.
     *
     * Dropping a link deletes that placement's own settings (its order
     * within the section, featured flag, per-section price). The form warns
     * before it happens; nothing about the product itself is touched, and a
     * product in no category at all is allowed — it simply isn't on the
     * menu yet.
     *
     * @param  array<int, int>  $categoryIds
     */
    protected function syncCategories(Product $product, array $categoryIds): void
    {
        $wanted = array_values(array_unique(array_map('intval', $categoryIds)));

        $product->menuItems()->whereNotIn('category_id', $wanted ?: [0])->delete();

        $existing = $product->menuItems()->pluck('category_id')->all();

        foreach (array_diff($wanted, $existing) as $categoryId) {
            $this->placeInCategory($product, $categoryId);
        }
    }

    /**
     * Adds the product to one category via a MenuItem — the same link Menu
     * Builder manages. A no-op if the product is already placed there.
     */
    protected function placeInCategory(Product $product, int $categoryId): void
    {
        $category = Category::find($categoryId);

        if (! $category || $category->menuItems()->where('product_id', $product->id)->exists()) {
            return;
        }

        $maxSortOrder = $category->menuItems()->max('sort_order');

        $category->menuItems()->create([
            'product_id' => $product->id,
            'menu_id' => $category->menu_id,
            'is_active' => true,
            'sort_order' => $maxSortOrder === null ? 0 : $maxSortOrder + 1,
        ]);
    }

    public function destroy(Product $product): RedirectResponse
    {
        if ($product->image_path) {
            Storage::disk('public')->delete($product->image_path);
        }

        $product->delete();

        return to_route('admin.products.index')->with('success', __('admin.products.deleted'));
    }

    /**
     * Every modifier group with its full modifier list (including each
     * modifier's global default price) — the Product form needs this to
     * let the admin set a per-product price/availability override, not
     * just pick which groups apply.
     */
    protected function modifierGroupOptions()
    {
        $defaultLocale = $this->registry->default()?->code;

        return ModifierGroup::query()
            ->with('modifiers.translations')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (ModifierGroup $group) => [
                'id' => $group->id,
                'name' => $group->translate($defaultLocale)?->name ?? "#{$group->id}",
                'is_required' => $group->is_required,
                'min_select' => $group->min_select,
                'max_select' => $group->max_select,
                'modifiers' => $group->modifiers->map(fn (Modifier $modifier) => [
                    'id' => $modifier->id,
                    'name' => $modifier->translate($defaultLocale)?->name ?? "#{$modifier->id}",
                    'price_cents' => $modifier->price_cents,
                    'is_active' => $modifier->is_active,
                ]),
            ]);
    }

    protected function tagOptions()
    {
        $defaultLocale = $this->registry->default()?->code;

        return Tag::query()->orderBy('sort_order')->get()->map(fn (Tag $tag) => [
            'id' => $tag->id,
            'name' => $tag->translate($defaultLocale)?->name ?? $tag->slug,
            'icon' => $tag->icon,
        ]);
    }

    /**
     * Every category the product could be filed under, walked depth-first so
     * a sub-category is listed directly beneath its parent and carries its
     * `depth` for indentation.
     *
     * A flat list was ambiguous the moment categories could nest: three
     * different "Grill" rows with nothing to tell them apart is not a
     * choice anybody can make correctly. Each option now knows both its own
     * name and the path that leads to it.
     *
     * @return array<int, array{id:int, name:string, path:string, depth:int}>
     */
    protected function categoryOptions(): array
    {
        $defaultLocale = $this->registry->default()?->code;

        $categories = Category::query()
            ->with('menu.translations', 'translations')
            ->orderBy('sort_order')
            ->get();

        $label = fn (Category $category) => $category->translate($defaultLocale)?->name ?? $category->slug;

        $options = [];

        $walk = function (?int $parentId, string $trail, int $depth) use (&$walk, $categories, $label, &$options) {
            foreach ($categories->where('parent_id', $parentId) as $category) {
                $path = $trail === '' ? $label($category) : "{$trail} ← {$label($category)}";

                $options[] = [
                    'id' => $category->id,
                    'name' => $label($category),
                    'path' => $path,
                    'depth' => $depth,
                ];

                $walk($category->id, $path, $depth + 1);
            }
        };

        // Top-level categories are the ones with no parent; each menu is a
        // root of its own so its name opens the trail.
        foreach ($categories->whereNull('parent_id')->groupBy('menu_id') as $inMenu) {
            $menu = $inMenu->first()->menu;
            $menuName = $menu?->translate($defaultLocale)?->name ?? $menu?->slug ?? '';

            foreach ($inMenu as $category) {
                $path = $menuName === '' ? $label($category) : "{$menuName} ← {$label($category)}";

                $options[] = [
                    'id' => $category->id,
                    'name' => $label($category),
                    'path' => $path,
                    'depth' => 0,
                ];

                $walk($category->id, $path, 1);
            }
        }

        return $options;
    }

    /**
     * Distinct station values across configured printers — mirrors
     * CategoryController::printerOptions(). A product's station is an
     * override of its category's, so it uses the same list.
     */
    protected function printerOptions()
    {
        return Printer::query()
            ->orderBy('station')
            ->get(['name', 'station'])
            ->unique('station')
            ->map(fn (Printer $printer) => [
                'station' => $printer->station,
                'name' => "{$printer->station} ({$printer->name})",
            ])
            ->values();
    }

    protected function present(Product $product, bool $includeTranslations = false): array
    {
        $activeCodes = $this->registry->codes();
        $translatedCodes = $product->translations->pluck('locale')->all();
        $defaultLocale = $this->registry->default()?->code;

        $payload = [
            'id' => $product->id,
            'slug' => $product->slug,
            'station' => $product->station,
            'image_url' => $product->image_url,
            'price_cents' => $product->price_cents,
            'prep_time_minutes' => $product->prep_time_minutes,
            'is_active' => $product->is_active,
            'sort_order' => $product->sort_order,
            'name' => $product->translate($defaultLocale)?->name ?? $product->slug,
            'tags' => $product->tags->map(fn (Tag $tag) => $tag->translate($defaultLocale)?->name ?? $tag->slug),
            // Read-only, informational: where this product currently
            // appears. Detaching happens in the Menu Builder, not here — a
            // product can be in several menus/categories at once.
            'menus' => $product->menus->unique('id')->map(fn ($menu) => $menu->translate($defaultLocale)?->name ?? $menu->slug)->values(),
            // The menu alone was not enough to find anything: several
            // sections of one menu hold the same product, so a row saying
            // only "قائمة الطعام" could not tell an admin whether the item
            // they were looking for was under "مشروبات ساخنة" or "عروض".
            // Each placement is therefore listed as its own menu/section
            // pair, and a product in three sections shows three of them.
            'placements' => $product->menuItems
                ->map(fn (MenuItem $item) => [
                    'id' => $item->id,
                    'menu' => $item->menu?->translate($defaultLocale)?->name ?? $item->menu?->slug,
                    'category' => $item->category?->translate($defaultLocale)?->name ?? $item->category?->slug,
                ])
                // A placement whose section was deleted out from under it
                // would otherwise render as an empty chip.
                ->filter(fn (array $placement) => $placement['menu'] !== null || $placement['category'] !== null)
                ->values(),
            'missing_locales' => array_values(array_diff($activeCodes, $translatedCodes)),
        ];

        if ($includeTranslations) {
            $payload['translations'] = collect($activeCodes)->mapWithKeys(function (string $code) use ($product) {
                $translation = $product->translate($code, withFallback: false);

                return [$code => [
                    'name' => $translation?->name ?? '',
                    'description' => $translation?->description ?? '',
                ]];
            });

            $payload['modifier_group_ids'] = $product->modifierGroups->pluck('id');
            // Keyed by modifier_id so the form can look up a modifier's
            // override in O(1) while rendering each group's modifier list.
            $payload['modifier_overrides'] = $product->modifierOverrides->mapWithKeys(fn ($override) => [
                $override->modifier_id => [
                    'price_cents' => $override->price_cents,
                    'is_active' => $override->is_active,
                ],
            ]);
            $payload['tag_ids'] = $product->tags->pluck('id');
            $payload['category_ids'] = $product->categories->pluck('id')->unique()->values();
        }

        return $payload;
    }
}
