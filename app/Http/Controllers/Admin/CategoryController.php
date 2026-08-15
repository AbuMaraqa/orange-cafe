<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\FillsMissingTranslations;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCategoryRequest;
use App\Http\Requests\Admin\UpdateCategoryRequest;
use App\Models\Category;
use App\Models\Menu;
use App\Models\Printer;
use App\Services\LanguageRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    use FillsMissingTranslations;

    public function __construct(protected LanguageRegistry $registry)
    {
    }

    public function index(): Response
    {
        // Categories nest to arbitrary depth, so the whole table is loaded
        // in one query and assembled into a tree in PHP (buildTree())
        // rather than eager-loading a fixed number of `children` levels.
        $all = Category::query()
            ->with('translations', 'menu.translations')
            ->orderBy('menu_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $topLevel = $all->whereNull('parent_id');

        return Inertia::render('Admin/Categories/Index', [
            'categories' => $topLevel->map(fn (Category $category) => [
                ...$this->present($category),
                'menu_name' => $category->menu?->name,
                'children' => $this->buildTree($category, $all),
            ])->values(),
            'languages' => $this->registry->active()->values(),
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Category>  $all
     */
    protected function buildTree(Category $category, $all): array
    {
        return $all->where('parent_id', $category->id)
            ->map(fn (Category $child) => [
                ...$this->present($child),
                'children' => $this->buildTree($child, $all),
            ])
            ->values()
            ->all();
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Categories/Form', [
            'languages' => $this->registry->active()->values(),
            'menuOptions' => $this->menuOptions(),
            'parentOptions' => $this->parentOptions(),
            'printerOptions' => $this->printerOptions(),
            'category' => null,
        ]);
    }

    public function store(StoreCategoryRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $translations = $this->fillMissingTranslations($data['translations'], $this->registry->default()?->code);
        unset($data['translations']);

        if (! empty($data['parent_id'])) {
            // Subcategories always inherit their parent's menu — never
            // trust a client-submitted menu_id for them.
            $data['menu_id'] = Category::findOrFail($data['parent_id'])->menu_id;
        }

        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('categories', 'public');
        }
        unset($data['image']);

        Category::create([...$data, ...$translations]);

        return to_route('admin.categories.index')->with('success', __('admin.categories.created'));
    }

    public function edit(Category $category): Response
    {
        $category->load('translations');

        return Inertia::render('Admin/Categories/Form', [
            'languages' => $this->registry->active()->values(),
            'menuOptions' => $this->menuOptions(),
            'parentOptions' => $this->parentOptions($category, forMenuId: $category->menu_id),
            'printerOptions' => $this->printerOptions(),
            'category' => $this->present($category, includeTranslations: true),
        ]);
    }

    public function update(UpdateCategoryRequest $request, Category $category): RedirectResponse
    {
        $data = $request->validated();
        $translations = $this->fillMissingTranslations($data['translations'], $this->registry->default()?->code);
        unset($data['translations']);

        if (! empty($data['parent_id'])) {
            $data['menu_id'] = Category::findOrFail($data['parent_id'])->menu_id;
        }

        if ($request->hasFile('image')) {
            if ($category->image_path) {
                Storage::disk('public')->delete($category->image_path);
            }
            $data['image_path'] = $request->file('image')->store('categories', 'public');
        }
        unset($data['image']);

        $category->update([...$data, ...$translations]);

        return to_route('admin.categories.index')->with('success', __('admin.categories.updated'));
    }

    public function destroy(Category $category): RedirectResponse
    {
        if ($category->image_path) {
            Storage::disk('public')->delete($category->image_path);
        }

        $category->delete();

        return to_route('admin.categories.index')->with('success', __('admin.categories.deleted'));
    }

    protected function menuOptions()
    {
        $defaultLocale = $this->registry->default()?->code;

        return Menu::query()->orderBy('sort_order')->get()->map(fn (Menu $menu) => [
            'id' => $menu->id,
            'name' => $menu->translate($defaultLocale)?->name ?? $menu->slug,
        ]);
    }

    /**
     * Any category can be a parent now (arbitrary depth), except the
     * category being edited itself and any of its own descendants — either
     * would create a cycle. Scoped to a menu when known, since a
     * subcategory must share its parent's menu. Labelled with the full
     * ancestor chain (e.g. "Drinks > Hot > Coffee") since plain names can
     * repeat at different places in the tree once nesting isn't capped
     * at one level.
     */
    protected function parentOptions(?Category $excluding = null, ?int $forMenuId = null)
    {
        $defaultLocale = $this->registry->default()?->code;
        $excludedIds = $excluding ? [$excluding->id, ...$excluding->descendantIds()] : [];

        $all = Category::query()
            ->with('translations')
            ->when($forMenuId, fn ($query) => $query->where('menu_id', $forMenuId))
            ->orderBy('sort_order')
            ->get()
            ->keyBy('id');

        $nameOf = fn (Category $category) => $category->translate($defaultLocale)?->name ?? $category->slug;

        $breadcrumb = function (Category $category) use ($all, $nameOf, &$breadcrumb) {
            $parent = $category->parent_id ? $all->get($category->parent_id) : null;

            return $parent ? $breadcrumb($parent).' > '.$nameOf($category) : $nameOf($category);
        };

        return $all->reject(fn (Category $category) => in_array($category->id, $excludedIds, true))
            ->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => $breadcrumb($category),
            ])
            ->values();
    }

    /**
     * Distinct station values across configured printers — several
     * printers can legitimately share a station (e.g. two grill
     * printers), so this is deduplicated by station, not one row per
     * printer. `station` on Category stays a plain string column
     * (KitchenPrinterService matches it verbatim against Printer::station),
     * this just stops admins from mistyping it by hand.
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

    protected function present(Category $category, bool $includeTranslations = false): array
    {
        $activeCodes = $this->registry->codes();
        $translatedCodes = $category->translations->pluck('locale')->all();

        $payload = [
            'id' => $category->id,
            'menu_id' => $category->menu_id,
            'parent_id' => $category->parent_id,
            'slug' => $category->slug,
            'station' => $category->station,
            'image_url' => $category->image_url,
            'is_active' => $category->is_active,
            'sort_order' => $category->sort_order,
            'name' => $category->translate($this->registry->default()?->code)?->name ?? $category->slug,
            'missing_locales' => array_values(array_diff($activeCodes, $translatedCodes)),
        ];

        if ($includeTranslations) {
            $payload['translations'] = collect($activeCodes)->mapWithKeys(function (string $code) use ($category) {
                $translation = $category->translate($code, withFallback: false);

                return [$code => [
                    'name' => $translation?->name ?? '',
                    'description' => $translation?->description ?? '',
                ]];
            });
        }

        return $payload;
    }
}
