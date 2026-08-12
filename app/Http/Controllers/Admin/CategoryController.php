<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCategoryRequest;
use App\Http\Requests\Admin\UpdateCategoryRequest;
use App\Models\Category;
use App\Models\Menu;
use App\Services\LanguageRegistry;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function __construct(protected LanguageRegistry $registry)
    {
    }

    public function index(): Response
    {
        $categories = Category::query()
            ->with('translations', 'children.translations', 'menu.translations')
            ->whereNull('parent_id')
            ->orderBy('menu_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return Inertia::render('Admin/Categories/Index', [
            'categories' => $categories->map(fn (Category $category) => [
                ...$this->present($category),
                'menu_name' => $category->menu?->name,
                'children' => $category->children->map(fn (Category $child) => $this->present($child)),
            ]),
            'languages' => $this->registry->active()->values(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Categories/Form', [
            'languages' => $this->registry->active()->values(),
            'menuOptions' => $this->menuOptions(),
            'parentOptions' => $this->parentOptions(),
            'category' => null,
        ]);
    }

    public function store(StoreCategoryRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $translations = $data['translations'];
        unset($data['translations']);

        if (! empty($data['parent_id'])) {
            // Subcategories always inherit their parent's menu — never
            // trust a client-submitted menu_id for them.
            $data['menu_id'] = Category::findOrFail($data['parent_id'])->menu_id;
        }

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
            'category' => $this->present($category, includeTranslations: true),
        ]);
    }

    public function update(UpdateCategoryRequest $request, Category $category): RedirectResponse
    {
        $data = $request->validated();
        $translations = $data['translations'];
        unset($data['translations']);

        if (! empty($data['parent_id'])) {
            $data['menu_id'] = Category::findOrFail($data['parent_id'])->menu_id;
        }

        $category->update([...$data, ...$translations]);

        return to_route('admin.categories.index')->with('success', __('admin.categories.updated'));
    }

    public function destroy(Category $category): RedirectResponse
    {
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
     * Top-level categories only, excluding the category being edited —
     * the only valid values for `parent_id` (menu stays exactly 2 levels).
     * Scoped to a menu when known, since a subcategory must share its
     * parent's menu.
     */
    protected function parentOptions(?Category $excluding = null, ?int $forMenuId = null)
    {
        $defaultLocale = $this->registry->default()?->code;

        return Category::query()
            ->whereNull('parent_id')
            ->when($forMenuId, fn ($query) => $query->where('menu_id', $forMenuId))
            ->when($excluding, fn ($query) => $query->whereKeyNot($excluding->id))
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->translate($defaultLocale)?->name ?? $category->slug,
            ]);
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
