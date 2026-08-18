<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\LanguageRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * Pure JSON API — deliberately NOT under the /{locale}/ URL prefix, since
 * localizing internal API endpoints by URL would only complicate clients.
 * Instead it resolves locale the way an API should: from the
 * `Accept-Language` request header, matched against active languages, with
 * the default language as fallback (per the API localization requirement).
 */
class MenuController extends Controller
{
    public function __invoke(Request $request, LanguageRegistry $languages): JsonResponse
    {
        $language = $languages->matchAcceptLanguage($request->header('Accept-Language'));

        // No active languages yet (fresh install, pre-seed) — serve the
        // configured fallback rather than 500-ing on a null language.
        $locale = $language?->code ?? config('app.locale');
        $direction = $language?->direction ?? 'ltr';

        App::setLocale($locale);

        $categories = Category::query()
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->with([
                'translations',
                'children' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order'),
                'children.translations',
                'children.products' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order'),
                'children.products.translations',
                'products' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order'),
                'products.translations',
            ])
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'locale' => $locale,
            'direction' => $direction,
            'categories' => $categories->map(fn (Category $category) => $this->presentCategory($category)),
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
                'products' => $child->products->map(fn ($product) => $this->presentProduct($product)),
            ]),
            'products' => $category->children->isEmpty()
                ? $category->products->map(fn ($product) => $this->presentProduct($product))
                : [],
        ];
    }

    protected function presentProduct($product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'description' => $product->description,
            'price_cents' => $product->price_cents,
        ];
    }
}
