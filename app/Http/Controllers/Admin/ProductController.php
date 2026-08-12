<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProductRequest;
use App\Http\Requests\Admin\UpdateProductRequest;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\Tag;
use App\Services\LanguageRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
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
            'product' => null,
        ]);
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $translations = $data['translations'];
        $modifierGroupIds = $data['modifier_group_ids'] ?? [];
        $tagIds = $data['tag_ids'] ?? [];
        unset($data['translations'], $data['modifier_group_ids'], $data['tag_ids']);

        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('products', 'public');
        }
        unset($data['image']);

        $product = Product::create([...$data, ...$translations]);
        $product->modifierGroups()->sync($modifierGroupIds);
        $product->tags()->sync($tagIds);

        return to_route('admin.products.index')->with('success', __('admin.products.created'));
    }

    public function edit(Product $product): Response
    {
        $product->load('translations', 'modifierGroups', 'tags', 'menus.translations');

        return Inertia::render('Admin/Products/Form', [
            'languages' => $this->registry->active()->values(),
            'modifierGroups' => $this->modifierGroupOptions(),
            'tags' => $this->tagOptions(),
            'product' => $this->present($product, includeTranslations: true),
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        $data = $request->validated();
        $translations = $data['translations'];
        $modifierGroupIds = $data['modifier_group_ids'] ?? [];
        $tagIds = $data['tag_ids'] ?? [];
        unset($data['translations'], $data['modifier_group_ids'], $data['tag_ids']);

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

        return to_route('admin.products.index')->with('success', __('admin.products.updated'));
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
            'is_active' => $product->is_active,
            'sort_order' => $product->sort_order,
            'name' => $product->translate($defaultLocale)?->name ?? $product->slug,
            'tags' => $product->tags->map(fn (Tag $tag) => $tag->translate($defaultLocale)?->name ?? $tag->slug),
            // Read-only, informational: where this product currently
            // appears. Attaching/detaching happens in the Menu Builder,
            // not here — a product can be in several menus at once.
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
        }

        return $payload;
    }
}
