<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Menu;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Which sections a product appears in is a set the administrator owns, not
 * a list that only grows.
 *
 * It used to only ever add: the categories a product was already in showed
 * as read-only chips, and the checklist excluded them. A product filed
 * under the wrong section could not be moved out of it from this form, so
 * in practice it got added to the right one as well and then appeared
 * twice on the menu.
 */
class ProductCategoryPlacementTest extends TestCase
{
    use RefreshDatabase;

    private ?User $admin = null;

    private function admin(): User
    {
        if ($this->admin) {
            return $this->admin;
        }

        $this->seed(RoleSeeder::class);
        $this->seed(LanguageSeeder::class);

        $user = User::create([
            'name' => 'Admin',
            'email' => 'admin@test.local',
            'password' => 'password',
            'locale' => 'ar',
            'email_verified_at' => now(),
        ]);
        $user->assignRole('admin');

        return $this->admin = $user;
    }

    private function menu(): Menu
    {
        return Menu::create([
            'slug' => 'm-'.fake()->unique()->numberBetween(1, 99999),
            'publish_status' => 'published',
            'is_active' => true,
            'sort_order' => 0,
            'ar' => ['name' => 'قائمة'],
            'en' => ['name' => 'Menu'],
        ]);
    }

    private function category(Menu $menu, string $name, ?Category $parent = null): Category
    {
        return Category::create([
            'menu_id' => $menu->id,
            'parent_id' => $parent?->id,
            'slug' => 'c-'.fake()->unique()->numberBetween(1, 99999),
            'is_active' => true,
            'sort_order' => 0,
            'ar' => ['name' => $name],
            'en' => ['name' => $name],
        ]);
    }

    private function product(): Product
    {
        return Product::create([
            'slug' => 'p-'.fake()->unique()->numberBetween(1, 99999),
            'price_cents' => 1000,
            'is_active' => true,
            'sort_order' => 0,
            'ar' => ['name' => 'عصير'],
            'en' => ['name' => 'Juice'],
        ]);
    }

    private function save(Product $product, array $categoryIds)
    {
        return $this->actingAs($this->admin())->patch(route('admin.products.update', $product), [
            'slug' => $product->slug,
            'price_cents' => $product->price_cents,
            'is_active' => true,
            'sort_order' => 0,
            'category_ids' => $categoryIds,
            'translations' => [
                'ar' => ['name' => 'عصير'],
                'en' => ['name' => 'Juice'],
            ],
        ]);
    }

    public function test_a_product_can_be_moved_from_one_section_to_another(): void
    {
        $menu = $this->menu();
        $drinks = $this->category($menu, 'مشروبات');
        $food = $this->category($menu, 'ماكولات');
        $product = $this->product();

        $this->save($product, [$drinks->id]);
        $this->assertSame([$drinks->id], $product->menuItems()->pluck('category_id')->all());

        $this->save($product, [$food->id]);

        $this->assertSame(
            [$food->id],
            $product->menuItems()->pluck('category_id')->all(),
            'moving it must not leave it on the menu twice'
        );
    }

    public function test_a_product_can_sit_in_a_sub_category(): void
    {
        $menu = $this->menu();
        $drinks = $this->category($menu, 'مشروبات');
        $cold = $this->category($menu, 'باردة', $drinks);
        $product = $this->product();

        $this->save($product, [$cold->id]);

        $this->assertSame([$cold->id], $product->menuItems()->pluck('category_id')->all());
    }

    public function test_a_product_can_sit_in_several_sections_at_once(): void
    {
        $menu = $this->menu();
        $drinks = $this->category($menu, 'مشروبات');
        $offers = $this->category($menu, 'عروض');
        $product = $this->product();

        $this->save($product, [$drinks->id, $offers->id]);

        $this->assertEqualsCanonicalizing(
            [$drinks->id, $offers->id],
            $product->menuItems()->pluck('category_id')->all()
        );
    }

    /**
     * A product in no section is a legitimate state — it exists in the
     * catalogue but is not on the menu yet.
     */
    public function test_a_product_can_be_taken_off_the_menu_entirely(): void
    {
        $menu = $this->menu();
        $drinks = $this->category($menu, 'مشروبات');
        $product = $this->product();

        $this->save($product, [$drinks->id]);
        $this->save($product, []);

        $this->assertSame(0, $product->menuItems()->count());
    }

    /**
     * Saving the same set twice must not churn the placement — its order
     * within the section and any section-specific price live on that row.
     */
    public function test_resaving_the_same_sections_keeps_the_placement_row(): void
    {
        $menu = $this->menu();
        $drinks = $this->category($menu, 'مشروبات');
        $product = $this->product();

        $this->save($product, [$drinks->id]);
        $placementId = $product->menuItems()->value('id');

        $this->save($product, [$drinks->id]);

        $this->assertSame($placementId, $product->menuItems()->value('id'));
    }
}
