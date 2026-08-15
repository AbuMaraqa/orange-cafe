<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\FillsMissingTranslations;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProductRequest;
use App\Http\Requests\Admin\UpdateProductRequest;
use App\Models\Category;
use App\Models\ModifierGroup;
use App\Models\Printer;
use App\Models\Product;
use App\Models\Tag;
use App\Services\LanguageRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    use FillsMissingTranslations;

    public function __construct(protected LanguageRegistry $registry)
    {
    }

    public function index(): Response
    {
        $products = Product::query()
            ->with(['translations', 'tags', 'menus.translations'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return Inertia::render('Admin/Products/Index', [
            'products' => $products->map(fn (Product $product) => $this->present($product)),
            'languages' => $this->registry->active()->values(),
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
        $tagIds = $data['tag_ids'] ?? [];
        $categoryIds = $data['category_ids'] ?? [];
        unset($data['translations'], $data['modifier_group_ids'], $data['tag_ids'], $data['category_ids']);

        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('products', 'public');
        }
        unset($data['image']);

        $product = Product::create([...$data, ...$translations]);
        $product->modifierGroups()->sync($modifierGroupIds);
        $product->tags()->sync($tagIds);

        foreach ($categoryIds as $categoryId) {
            $this->placeInCategory($product, $categoryId);
        }

        return to_route('admin.products.index')->with('success', __('admin.products.created'));
    }

    public function edit(Product $product): Response
    {
        $product->load('translations', 'modifierGroups', 'tags', 'menus.translations', 'categories');

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
        $tagIds = $data['tag_ids'] ?? [];
        $categoryIds = $data['category_ids'] ?? [];
        unset($data['translations'], $data['modifier_group_ids'], $data['tag_ids'], $data['category_ids']);

        if ($request->hasFile('image')) {
            if ($product->image_path) {
                Storage::disk('public')->delete($product->image_path);
            }
            $data['image_path'] = $request->file('image')->store('products', 'public');
        }
        unset($data['image']);

        $product->update([...$data, ...$translations]);
        $product->modifierGroups()->sync($modifierGroupIds);
        $product->tags()->sync($tagIds);

        foreach ($categoryIds as $categoryId) {
            $this->placeInCategory($product, $categoryId);
        }

        return to_route('admin.products.index')->with('success', __('admin.products.updated'));
    }

    /**
     * Adds the product to a category via a MenuItem — the same link Menu
     * Builder manages. Selecting categories here is just a shortcut for
     * placement; it never replaces or removes existing ones, and is a
     * no-op if the product is already placed in that exact category.
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

    protected function modifierGroupOptions()
    {
        $defaultLocale = $this->registry->default()?->code;

        return ModifierGroup::query()->orderBy('sort_order')->get()->map(fn (ModifierGroup $group) => [
            'id' => $group->id,
            'name' => $group->translate($defaultLocale)?->name ?? "#{$group->id}",
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

    protected function categoryOptions()
    {
        $defaultLocale = $this->registry->default()?->code;

        return Category::query()->with('menu.translations')->orderBy('sort_order')->get()->map(fn (Category $category) => [
            'id' => $category->id,
            // Prefixed with the menu name since the same category name
            // (e.g. "Grill") can exist under more than one menu.
            'name' => ($category->menu?->translate($defaultLocale)?->name ?? $category->menu?->slug)
                .' — '.($category->translate($defaultLocale)?->name ?? $category->slug),
        ]);
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
            $payload['tag_ids'] = $product->tags->pluck('id');
            $payload['category_ids'] = $product->categories->pluck('id')->unique()->values();
        }

        return $payload;
    }
}
