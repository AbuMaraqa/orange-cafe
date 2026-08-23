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
 * The menu nests as deep as the cafe builds it.
 *
 * The presenter used to write out exactly two levels by hand, so a menu
 * grouped "مشروبات ← ساخنة ← قهوة" lost the third: those products were
 * visible in the admin panel and unreachable from the customer menu.
 */
class NestedMenuDepthTest extends TestCase
{
    use RefreshDatabase;

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

    private function productIn(Category $category, string $name): void
    {
        $product = Product::create([
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'price_cents' => 1500,
            'is_active' => true,
            'sort_order' => 0,
            'ar' => ['name' => $name],
            'en' => ['name' => $name],
        ]);

        MenuItem::create([
            'menu_id' => $category->menu_id,
            'category_id' => $category->id,
            'product_id' => $product->id,
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    private function threeLevelMenu(): Menu
    {
        $this->seed(LanguageSeeder::class);

        $menu = Menu::create([
            'slug' => 'test-menu',
            'publish_status' => 'published',
            'is_active' => true,
            'sort_order' => 0,
            'ar' => ['name' => 'القائمة'],
            'en' => ['name' => 'Menu'],
        ]);

        $drinks = $this->category($menu, 'مشروبات');
        $hot = $this->category($menu, 'ساخنة', $drinks);
        $coffee = $this->category($menu, 'قهوة', $hot);

        $this->productIn($coffee, 'إسبريسو');

        return $menu;
    }

    public function test_a_third_level_section_survives_into_the_payload(): void
    {
        $this->threeLevelMenu();

        $payload = app(MenuCatalogPresenter::class)->visibleMenusPayload();

        $top = collect($payload)->firstOrFail()['categories'][0];
        $second = $top['subcategories'][0];
        $third = $second['subcategories'][0] ?? null;

        $this->assertNotNull($third, 'the third level must not be dropped');
        $this->assertSame('قهوة', $third['name']);
    }

    public function test_products_three_levels_down_are_orderable(): void
    {
        $this->threeLevelMenu();

        $payload = app(MenuCatalogPresenter::class)->visibleMenusPayload();
        $third = collect($payload)->firstOrFail()['categories'][0]['subcategories'][0]['subcategories'][0];

        $this->assertCount(1, $third['products']);
        $this->assertSame('إسبريسو', $third['products'][0]['name']);
        $this->assertArrayHasKey('menu_item_id', $third['products'][0]);
    }

    /**
     * A grouping section lists the dishes filed under it and not the ones
     * filed under its children — those belong to the screen you reach by
     * opening the child.
     */
    public function test_a_grouping_section_does_not_absorb_its_children_dishes(): void
    {
        $this->threeLevelMenu();

        $payload = app(MenuCatalogPresenter::class)->visibleMenusPayload();
        $top = collect($payload)->firstOrFail()['categories'][0];

        $this->assertCount(0, $top['products']);
        $this->assertCount(0, $top['subcategories'][0]['products']);
    }

    /**
     * A section can sell and group at the same time.
     *
     * This used to drop the section's own dishes the moment it had a child,
     * so a cafe with "مشروبات" holding both a house special and a "ساخنة"
     * sub-section lost the special entirely: orderable in the admin panel,
     * invisible to every customer.
     */
    public function test_a_section_keeps_its_own_dishes_alongside_its_sub_sections(): void
    {
        $menu = $this->threeLevelMenu();
        $drinks = $menu->categories()->whereNull('parent_id')->firstOrFail();

        $this->productIn($drinks, 'عصير الشيف');

        $payload = app(MenuCatalogPresenter::class)->visibleMenusPayload();
        $top = collect($payload)->firstOrFail()['categories'][0];

        $this->assertCount(1, $top['products'], 'the house special must survive');
        $this->assertSame('عصير الشيف', $top['products'][0]['name']);
        $this->assertCount(1, $top['subcategories'], 'and it must still group its sub-sections');
    }

    /**
     * The count on a section tile is the whole subtree — a customer looking
     * at "مشروبات" is asking whether there is anything in there at all, not
     * how many dishes happen to sit at that exact level.
     */
    public function test_a_section_counts_everything_beneath_it(): void
    {
        $menu = $this->threeLevelMenu();
        $drinks = $menu->categories()->whereNull('parent_id')->firstOrFail();

        $this->productIn($drinks, 'عصير الشيف');

        $payload = app(MenuCatalogPresenter::class)->visibleMenusPayload();
        $top = collect($payload)->firstOrFail()['categories'][0];

        $this->assertSame(2, $top['product_count'], 'the special here plus the espresso two levels down');
    }
}
