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
 * The menu builder is the screen an administrator actually assembles a
 * menu on, and it used to show strictly less than the system stores: one
 * flat level of sections, and a dropdown of every product in the
 * database added one page-load at a time.
 *
 * The customer menu has always rendered sections to arbitrary depth
 * (MenuCatalogPresenter) and the product form has always listed them
 * that way, so a sub-section was creatable everywhere except the one
 * page named after building menus.
 */
class MenuBuilderTest extends TestCase
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

    private function section(Menu $menu, ?Category $parent = null, string $name = 'قسم'): Category
    {
        return Category::create([
            'menu_id' => $menu->id,
            'parent_id' => $parent?->id,
            'slug' => 's-'.fake()->unique()->numberBetween(1, 99999),
            'is_active' => true,
            'sort_order' => 0,
            'ar' => ['name' => $name],
            'en' => ['name' => $name],
        ]);
    }

    private function product(string $name = 'منتج', int $price = 500): Product
    {
        return Product::create([
            'slug' => 'p-'.fake()->unique()->numberBetween(1, 99999),
            'price_cents' => $price,
            'is_active' => true,
            'sort_order' => 0,
            'ar' => ['name' => $name],
            'en' => ['name' => $name],
        ]);
    }

    public function test_the_builder_shows_sections_nested_to_any_depth(): void
    {
        $menu = $this->menu();
        $drinks = $this->section($menu, null, 'مشروبات');
        $hot = $this->section($menu, $drinks, 'ساخنة');
        $coffee = $this->section($menu, $hot, 'قهوة');

        $this->actingAs($this->admin())
            ->get(route('admin.menus.builder', $menu))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Menus/Builder')
                ->where('sections.0.id', $drinks->id)
                ->where('sections.0.children.0.id', $hot->id)
                ->where('sections.0.children.0.children.0.id', $coffee->id));
    }

    public function test_a_parent_reports_the_whole_subtree_in_its_count(): void
    {
        $menu = $this->menu();
        $drinks = $this->section($menu, null, 'مشروبات');
        $hot = $this->section($menu, $drinks, 'ساخنة');

        $drinks->menuItems()->create([
            'menu_id' => $menu->id, 'product_id' => $this->product()->id, 'is_active' => true, 'sort_order' => 0,
        ]);
        $hot->menuItems()->create([
            'menu_id' => $menu->id, 'product_id' => $this->product()->id, 'is_active' => true, 'sort_order' => 0,
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.menus.builder', $menu))
            ->assertInertia(fn ($page) => $page
                ->where('sections.0.total_item_count', 2)
                ->where('sections.0.children.0.total_item_count', 1));
    }

    public function test_several_products_are_added_in_one_request(): void
    {
        $menu = $this->menu();
        $section = $this->section($menu);
        $products = collect(range(1, 3))->map(fn () => $this->product());

        $this->actingAs($this->admin())
            ->post(route('admin.menus.items.store', $section), [
                'product_ids' => $products->pluck('id')->all(),
            ])
            ->assertRedirect();

        $this->assertSame(3, $section->menuItems()->count());
        // Order follows the order they were picked in, appended after
        // whatever the section already held.
        $this->assertSame([0, 1, 2], $section->menuItems()->orderBy('sort_order')->pluck('sort_order')->all());
    }

    public function test_a_bulk_add_leaves_every_product_on_its_own_price(): void
    {
        $menu = $this->menu();
        $section = $this->section($menu);
        $products = collect([$this->product('أ', 300), $this->product('ب', 700)]);

        $this->actingAs($this->admin())
            ->post(route('admin.menus.items.store', $section), [
                'product_ids' => $products->pluck('id')->all(),
                // Meaningless for a batch — a single override cannot be
                // right for two differently priced products.
                'price_cents' => 999,
            ]);

        $this->assertSame([null, null], $section->menuItems()->pluck('price_cents')->all());
    }

    public function test_a_single_add_still_takes_a_price_override(): void
    {
        $menu = $this->menu();
        $section = $this->section($menu);
        $product = $this->product('أ', 300);

        $this->actingAs($this->admin())
            ->post(route('admin.menus.items.store', $section), [
                'product_ids' => [$product->id],
                'price_cents' => 999,
            ]);

        $this->assertSame(999, $section->menuItems()->first()->price_cents);
    }

    public function test_the_same_product_cannot_be_added_to_one_section_twice(): void
    {
        $menu = $this->menu();
        $section = $this->section($menu);
        $product = $this->product();

        $this->actingAs($this->admin())
            ->post(route('admin.menus.items.store', $section), ['product_ids' => [$product->id]]);

        $this->actingAs($this->admin())
            ->post(route('admin.menus.items.store', $section), ['product_ids' => [$product->id]])
            ->assertSessionHasErrors('product_ids.0');

        $this->assertSame(1, $section->menuItems()->count());
    }

    /**
     * The picker used to hide every product already placed anywhere in the
     * menu, but uniqueness is per section — a house special belongs in
     * both its own section and a "most ordered" one.
     */
    public function test_the_same_product_can_sit_in_two_sections_of_one_menu(): void
    {
        $menu = $this->menu();
        $coffee = $this->section($menu, null, 'قهوة');
        $popular = $this->section($menu, null, 'الأكثر طلباً');
        $product = $this->product();

        $this->actingAs($this->admin())
            ->post(route('admin.menus.items.store', $coffee), ['product_ids' => [$product->id]]);

        $this->actingAs($this->admin())
            ->post(route('admin.menus.items.store', $popular), ['product_ids' => [$product->id]])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $coffee->menuItems()->count());
        $this->assertSame(1, $popular->menuItems()->count());
    }

    public function test_the_picker_offers_products_already_placed_elsewhere_in_the_menu(): void
    {
        $menu = $this->menu();
        $coffee = $this->section($menu, null, 'قهوة');
        $product = $this->product();

        $coffee->menuItems()->create([
            'menu_id' => $menu->id, 'product_id' => $product->id, 'is_active' => true, 'sort_order' => 0,
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.menus.builder', $menu))
            ->assertInertia(fn ($page) => $page
                ->where('availableProducts', fn ($products) => collect($products)->contains('id', $product->id)));
    }

    public function test_a_sub_section_can_be_created_from_the_builder(): void
    {
        $menu = $this->menu();
        $parent = $this->section($menu, null, 'مشروبات');

        $this->actingAs($this->admin())
            ->post(route('admin.menus.sections.store', $menu), [
                'slug' => 'hot-drinks',
                'parent_id' => $parent->id,
                'translations' => ['ar' => ['name' => 'ساخنة'], 'en' => ['name' => 'Hot']],
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('categories', [
            'slug' => 'hot-drinks',
            'menu_id' => $menu->id,
            'parent_id' => $parent->id,
        ]);
    }

    public function test_a_sub_section_cannot_be_parented_into_another_menu(): void
    {
        $menu = $this->menu();
        $foreignSection = $this->section($this->menu(), null, 'قسم في قائمة أخرى');

        $this->actingAs($this->admin())
            ->post(route('admin.menus.sections.store', $menu), [
                'slug' => 'stray',
                'parent_id' => $foreignSection->id,
                'translations' => ['ar' => ['name' => 'قسم'], 'en' => ['name' => 'Section']],
            ])
            ->assertSessionHasErrors('parent_id');
    }

    public function test_sub_sections_are_ordered_among_their_siblings_not_the_top_level(): void
    {
        $menu = $this->menu();
        // Two top-level sections already occupy sort_order 0 and 1.
        $this->section($menu, null, 'أول')->update(['sort_order' => 0]);
        $parent = $this->section($menu, null, 'ثاني');
        $parent->update(['sort_order' => 1]);

        $this->actingAs($this->admin())
            ->post(route('admin.menus.sections.store', $menu), [
                'slug' => 'first-child',
                'parent_id' => $parent->id,
                'translations' => ['ar' => ['name' => 'فرعي'], 'en' => ['name' => 'Child']],
            ]);

        // The first child of a parent starts at 0, not after the top-level
        // sections it has nothing to do with.
        $this->assertSame(0, Category::where('slug', 'first-child')->value('sort_order'));
    }

    public function test_reordering_only_touches_the_level_that_was_dragged(): void
    {
        $menu = $this->menu();
        $first = $this->section($menu, null, 'أول');
        $second = $this->section($menu, null, 'ثاني');
        $child = $this->section($menu, $first, 'فرعي');
        $child->update(['sort_order' => 7]);

        $this->actingAs($this->admin())
            ->patch(route('admin.menus.sections.reorder', $menu), [
                'ids' => [$second->id, $first->id],
            ])
            ->assertRedirect();

        $this->assertSame(0, $second->fresh()->sort_order);
        $this->assertSame(1, $first->fresh()->sort_order);
        // A same-numbered sub-section elsewhere in the tree is untouched.
        $this->assertSame(7, $child->fresh()->sort_order);
    }

    public function test_sub_sections_can_be_reordered_within_their_parent(): void
    {
        $menu = $this->menu();
        $parent = $this->section($menu, null, 'مشروبات');
        $hot = $this->section($menu, $parent, 'ساخنة');
        $cold = $this->section($menu, $parent, 'باردة');

        $this->actingAs($this->admin())
            ->patch(route('admin.menus.sections.reorder', $menu), [
                'parent_id' => $parent->id,
                'ids' => [$cold->id, $hot->id],
            ]);

        $this->assertSame(0, $cold->fresh()->sort_order);
        $this->assertSame(1, $hot->fresh()->sort_order);
    }

    public function test_a_section_can_be_switched_off_from_the_builder(): void
    {
        $menu = $this->menu();
        $section = $this->section($menu);

        $this->actingAs($this->admin())
            ->patch(route('admin.menus.sections.update', $section), ['is_active' => false])
            ->assertRedirect();

        $this->assertFalse($section->fresh()->is_active);
    }

    /**
     * The whole tree is read in one flat query and rebuilt from parent_id
     * in memory, so adding depth must not add queries. Sections are also
     * handed to their own items so the station fallback
     * (MenuItem::effectiveStation) does not re-read one category row per
     * line.
     */
    public function test_depth_does_not_cost_extra_queries(): void
    {
        $admin = $this->admin();

        $count = function (int $depth) use ($admin) {
            $menu = $this->menu();
            $parent = null;

            for ($level = 0; $level < $depth; $level++) {
                $parent = $this->section($menu, $parent, "مستوى {$level}");
                $parent->menuItems()->create([
                    'menu_id' => $menu->id,
                    'product_id' => $this->product()->id,
                    'is_active' => true,
                    'sort_order' => 0,
                ]);
            }

            $queries = 0;
            \DB::listen(function () use (&$queries) {
                $queries++;
            });

            $this->actingAs($admin)->get(route('admin.menus.builder', $menu))->assertOk();

            return $queries;
        };

        // Discarded: the first request of the test also pays for session
        // and permission lookups that are cached from then on.
        $count(1);

        $shallow = $count(1);
        $deep = $count(5);

        $this->assertLessThanOrEqual(
            $shallow,
            $deep,
            "1 level took {$shallow} queries, 5 levels took {$deep}.",
        );
    }

    /**
     * A line can be active in the menu while the product behind it is
     * switched off globally, in which case the customer never sees it —
     * the builder has to say so or the section simply looks wrong.
     */
    public function test_a_line_reports_when_its_product_is_globally_inactive(): void
    {
        $menu = $this->menu();
        $section = $this->section($menu);
        $product = $this->product();
        $product->update(['is_active' => false]);

        $section->menuItems()->create([
            'menu_id' => $menu->id, 'product_id' => $product->id, 'is_active' => true, 'sort_order' => 0,
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.menus.builder', $menu))
            ->assertInertia(fn ($page) => $page
                ->where('sections.0.items.0.is_active', true)
                ->where('sections.0.items.0.product_is_active', false));
    }
}
