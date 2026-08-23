<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Product;
use App\Services\MenuCatalogPresenter;
use Database\Seeders\LanguageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Dishes are listed cheapest first, in every section.
 *
 * The ordering has to come from the price the customer is actually shown,
 * which is not a single column: a menu item may override the product's own
 * price for that menu, so sorting on either column alone puts the
 * overridden items in the wrong place.
 */
class MenuPriceOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_dishes_are_listed_cheapest_first(): void
    {
        $menu = $this->menu();
        $section = $this->category($menu, 'مشروبات');

        // Deliberately added expensive-first, so passing cannot be an
        // accident of insertion order.
        $this->productIn($section, 'عصير طازج', 1800);
        $this->productIn($section, 'شاي', 500);
        $this->productIn($section, 'قهوة', 1200);

        $this->assertSame([500, 1200, 1800], $this->pricesIn($menu, 0));
    }

    public function test_a_menus_price_override_decides_the_position(): void
    {
        $menu = $this->menu();
        $section = $this->category($menu, 'مشروبات');

        $this->productIn($section, 'شاي', 500);
        // Expensive as a product, cheap on this menu — it belongs first.
        $this->productIn($section, 'قهوة مخفضة', 2000, overrideCents: 200);

        $this->assertSame([200, 500], $this->pricesIn($menu, 0));
    }

    public function test_a_subsection_is_sorted_too(): void
    {
        $menu = $this->menu();
        $drinks = $this->category($menu, 'مشروبات');
        $hot = $this->category($menu, 'ساخنة', $drinks);

        $this->productIn($hot, 'لاتيه', 1500);
        $this->productIn($hot, 'شاي', 400);

        $payload = app(MenuCatalogPresenter::class)->visibleMenusPayload();
        $subsection = collect($payload)->firstOrFail()['categories'][0]['subcategories'][0];

        $this->assertSame([400, 1500], collect($subsection['products'])->pluck('price_cents')->all());
    }

    public function test_dishes_at_the_same_price_keep_the_order_the_admin_arranged(): void
    {
        $menu = $this->menu();
        $section = $this->category($menu, 'مشروبات');

        $this->productIn($section, 'شاي', 500, sortOrder: 2);
        $this->productIn($section, 'نعناع', 500, sortOrder: 1);
        $this->productIn($section, 'قهوة', 300, sortOrder: 3);

        $payload = app(MenuCatalogPresenter::class)->visibleMenusPayload();
        $products = collect($payload)->firstOrFail()['categories'][0]['products'];

        $this->assertSame(
            ['قهوة', 'نعناع', 'شاي'],
            collect($products)->pluck('name')->all(),
            'A price sort must not throw away the order the admin set within one price.'
        );
    }

    /** @return array<int, int> */
    private function pricesIn(Menu $menu, int $categoryIndex): array
    {
        $payload = app(MenuCatalogPresenter::class)->visibleMenusPayload();

        return collect($payload)->firstOrFail()['categories'][$categoryIndex]['products']
            ->pluck('price_cents')
            ->all();
    }

    private function menu(): Menu
    {
        $this->seed(LanguageSeeder::class);

        return Menu::create([
            'slug' => 'test-menu',
            'publish_status' => 'published',
            'is_active' => true,
            'sort_order' => 0,
            'ar' => ['name' => 'القائمة'],
            'en' => ['name' => 'Menu'],
        ]);
    }

    private function category(Menu $menu, string $name, ?Category $parent = null): Category
    {
        return Category::create([
            'menu_id' => $menu->id,
            'parent_id' => $parent?->id,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'is_active' => true,
            'sort_order' => 0,
            'ar' => ['name' => $name],
            'en' => ['name' => $name],
        ]);
    }

    private function productIn(
        Category $category,
        string $name,
        int $priceCents,
        ?int $overrideCents = null,
        int $sortOrder = 0,
    ): void {
        $product = Product::create([
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'price_cents' => $priceCents,
            'is_active' => true,
            'sort_order' => 0,
            'ar' => ['name' => $name],
            'en' => ['name' => $name],
        ]);

        MenuItem::create([
            'menu_id' => $category->menu_id,
            'category_id' => $category->id,
            'product_id' => $product->id,
            'price_cents' => $overrideCents,
            'is_active' => true,
            'sort_order' => $sortOrder,
        ]);
    }
}
