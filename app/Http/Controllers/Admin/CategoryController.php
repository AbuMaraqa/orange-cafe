<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCategoryRequest;
use App\Http\Requests\Admin\UpdateCategoryRequest;
use App\Models\Category;
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
            ->with('translations', 'children.translations')
            ->whereNull('parent_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return Inertia::render('Admin/Categories/Index', [
            'categories' => $categories->map(fn (Category $category) => [
                ...$this->present($category),
                'children' => $category->children->map(fn (Category $child) => $this->present($child)),
            ]),
            'languages' => $this->registry->active()->values(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Categories/Form', [
            'languages' => $this->registry->active()->values(),
            'parentOptions' => $this->parentOptions(),
            'category' => null,
        ]);
    }

    public function store(StoreCategoryRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $translations = $data['translations'];
        unset($data['translations']);

        Category::create([...$data, ...$translations]);

        return to_route('admin.categories.index')->with('success', __('admin.categories.created'));
    }

    public function edit(Category $category): Response
    {
        $category->load('translations');

        return Inertia::render('Admin/Categories/Form', [
            'languages' => $this->registry->active()->values(),
            'parentOptions' => $this->parentOptions($category),
            'category' => $this->present($category, includeTranslations: true),
        ]);
    }

    public function update(UpdateCategoryRequest $request, Category $category): RedirectResponse
    {
        $data = $request->validated();
        $translations = $data['translations'];
        unset($data['translations']);

        $category->update([...$data, ...$translations]);

        return to_route('admin.categories.index')->with('success', __('admin.categories.updated'));
    }

    public function destroy(Category $category): RedirectResponse
    {
        $category->delete();

        return to_route('admin.categories.index')->with('success', __('admin.categories.deleted'));
    }

    /**
     * Top-level categories only, excluding the category being edited —
     * the only valid values for `parent_id` (menu stays exactly 2 levels).
     */
    protected function parentOptions(?Category $excluding = null)
    {
        $defaultLocale = $this->registry->default()?->code;

        return Category::query()
            ->whereNull('parent_id')
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
