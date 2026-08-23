<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Menu;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Finding a product, and the small edits made straight from the list.
 *
 * The catalog is 148 products across several menus, and until now the list
 * screen showed all of them, unfiltered, with the only way in being the
 * browser's own find-in-page — which searches the rendered default-locale
 * name and nothing else. Anything entered from an English price list was
 * effectively unreachable, as was the question that comes up most often
 * before a menu goes out: which products are not on a menu at all.
 */
class ProductCatalogFiltersTest extends TestCase
{
    use RefreshDatabase;

    private ?User $admin = null;

    /**
     * Languages are seeded before anything else is created, not lazily
     * alongside the admin user.
     *
     * Astrotomic only persists a translation for a locale that is in
     * translatable.locales, and this app fills that list from the
     * `languages` table (LanguageRegistry::syncRuntimeConfig). With an empty
     * table the registry falls back to a single locale, so a product created
     * first would silently lose its English name — and every assertion about
     * searching or about missing translations would then be testing the
     * fixture rather than the code.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(LanguageSeeder::class);
    }

    private function admin(): User
    {
        if ($this->admin) {
            return $this->admin;
        }

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

    private function menu(string $name = 'قائمة'): Menu
    {
        return Menu::create([
            'slug' => 'm-'.fake()->unique()->numberBetween(1, 99999),
            'publish_status' => 'published',
            'is_active' => true,
            'sort_order' => 0,
            'ar' => ['name' => $name],
            'en' => ['name' => $name],
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

    /**
     * @param  array<string, string>  $names  locale => name
     */
    private function product(array $names, bool $active = true, ?string $slug = null): Product
    {
        $attributes = [
            'slug' => $slug ?? 'p-'.fake()->unique()->numberBetween(1, 99999),
            'price_cents' => 500,
            'is_active' => $active,
            'sort_order' => 0,
        ];

        foreach ($names as $locale => $name) {
            $attributes[$locale] = ['name' => $name];
        }

        return Product::create($attributes);
    }

    /**
     * @return array<int, int> the ids the list came back with, in order
     */
    private function listIds(array $query = []): array
    {
        $ids = [];

        $this->actingAs($this->admin())
            ->get(route('admin.products.index', $query))
            ->assertOk()
            ->assertInertia(function ($page) use (&$ids) {
                $page->component('Admin/Products/Index');
                $ids = array_column($page->toArray()['props']['products'], 'id');
            });

        return $ids;
    }

    public function test_search_matches_the_default_locale_name(): void
    {
        $juice = $this->product(['ar' => 'عصير برتقال', 'en' => 'Orange juice']);
        $this->product(['ar' => 'قهوة عربية', 'en' => 'Arabic coffee']);

        $this->assertSame([$juice->id], $this->listIds(['search' => 'برتقال']));
    }

    /**
     * The reason the search goes through the translations table rather than
     * the presented name: the panel runs in Arabic, so a product entered
     * from an English price list would otherwise be findable only by a name
     * the screen never displays.
     */
    public function test_search_matches_a_non_default_locale_name(): void
    {
        $juice = $this->product(['ar' => 'عصير برتقال', 'en' => 'Orange juice']);
        $this->product(['ar' => 'قهوة عربية', 'en' => 'Arabic coffee']);

        $this->assertSame([$juice->id], $this->listIds(['search' => 'Orange']));
    }

    public function test_search_matches_the_slug(): void
    {
        $juice = $this->product(['ar' => 'عصير برتقال'], slug: 'aasyr-brtkal');
        $this->product(['ar' => 'قهوة عربية'], slug: 'kho-aarby');

        $this->assertSame([$juice->id], $this->listIds(['search' => 'brtkal']));
    }

    public function test_the_status_filter_separates_active_from_inactive(): void
    {
        $on = $this->product(['ar' => 'متاح']);
        $off = $this->product(['ar' => 'موقوف'], active: false);

        $this->assertSame([$on->id], $this->listIds(['status' => 'active']));
        $this->assertSame([$off->id], $this->listIds(['status' => 'inactive']));
    }

    public function test_products_can_be_narrowed_to_one_section(): void
    {
        $menu = $this->menu();
        $hot = $this->section($menu, null, 'ساخنة');
        $cold = $this->section($menu, null, 'باردة');

        $coffee = $this->product(['ar' => 'قهوة']);
        $juice = $this->product(['ar' => 'عصير']);

        $coffee->menuItems()->create(['menu_id' => $menu->id, 'category_id' => $hot->id, 'sort_order' => 0]);
        $juice->menuItems()->create(['menu_id' => $menu->id, 'category_id' => $cold->id, 'sort_order' => 0]);

        $this->assertSame([$coffee->id], $this->listIds(['category_id' => $hot->id]));
        $this->assertSame([$juice->id], $this->listIds(['menu_id' => $menu->id, 'category_id' => $cold->id]));
    }

    public function test_products_can_be_narrowed_to_one_tag(): void
    {
        $tag = Tag::create([
            'slug' => 'vegan',
            'sort_order' => 0,
            'ar' => ['name' => 'نباتي'],
            'en' => ['name' => 'Vegan'],
        ]);

        $tagged = $this->product(['ar' => 'سلطة']);
        $this->product(['ar' => 'برجر']);

        $tagged->tags()->attach($tag);

        $this->assertSame([$tagged->id], $this->listIds(['tag_id' => $tag->id]));
    }

    /**
     * The one an admin cannot get at any other way: a product with no
     * placement is invisible to customers and absent from Menu Builder, so
     * nothing surfaces it until someone asks why it never sells.
     */
    public function test_products_that_are_on_no_menu_can_be_listed(): void
    {
        $menu = $this->menu();
        $section = $this->section($menu);

        $placed = $this->product(['ar' => 'مُدرج']);
        $orphan = $this->product(['ar' => 'يتيم']);

        $placed->menuItems()->create(['menu_id' => $menu->id, 'category_id' => $section->id, 'sort_order' => 0]);

        $this->assertSame([$orphan->id], $this->listIds(['placement' => 'unplaced']));
        $this->assertSame([$placed->id], $this->listIds(['placement' => 'placed']));
    }

    public function test_products_missing_a_translation_can_be_listed(): void
    {
        $complete = $this->product(['ar' => 'كامل', 'en' => 'Complete']);
        $partial = $this->product(['ar' => 'ناقص']);

        $this->assertSame([$partial->id], $this->listIds(['translations' => 'missing']));
        $this->assertContains($complete->id, $this->listIds());
    }

    public function test_filters_combine_rather_than_replace_each_other(): void
    {
        $menu = $this->menu();
        $section = $this->section($menu);

        $wanted = $this->product(['ar' => 'عصير برتقال']);
        $wrongSection = $this->product(['ar' => 'عصير ليمون']);
        $inactive = $this->product(['ar' => 'عصير مانجو'], active: false);

        foreach ([$wanted, $inactive] as $product) {
            $product->menuItems()->create(['menu_id' => $menu->id, 'category_id' => $section->id, 'sort_order' => 0]);
        }

        $this->assertSame([$wanted->id], $this->listIds([
            'search' => 'عصير',
            'category_id' => $section->id,
            'status' => 'active',
        ]));

        $this->assertNotContains($wrongSection->id, $this->listIds(['category_id' => $section->id]));
    }

    public function test_the_total_count_ignores_the_filters(): void
    {
        $this->product(['ar' => 'واحد']);
        $this->product(['ar' => 'اثنان']);
        $this->product(['ar' => 'ثلاثة'], active: false);

        $this->actingAs($this->admin())
            ->get(route('admin.products.index', ['status' => 'inactive']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('products', 1)
                ->where('totalCount', 3)
                ->where('filters.status', 'inactive'));
    }

    /**
     * A filter arriving in a bookmarked or shared URL has to show up in the
     * controls, not only in the results — the bar is rendered from these.
     */
    public function test_an_unrecognised_filter_value_is_discarded(): void
    {
        $this->product(['ar' => 'منتج']);

        $this->actingAs($this->admin())
            ->get(route('admin.products.index', ['status' => 'nonsense', 'placement' => 'nonsense']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('products', 1)
                ->where('filters.status', null)
                ->where('filters.placement', null));
    }

    public function test_a_product_is_switched_off_from_the_list(): void
    {
        $product = $this->product(['ar' => 'منتج']);

        $this->actingAs($this->admin())
            ->patch(route('admin.products.toggle-active', $product))
            ->assertRedirect();

        $this->assertFalse($product->fresh()->is_active);

        // Derived server-side rather than sent by the client, so a second
        // tap turns it back on instead of repeating the first one.
        $this->actingAs($this->admin())
            ->patch(route('admin.products.toggle-active', $product));

        $this->assertTrue($product->fresh()->is_active);
    }

    public function test_an_arabic_name_still_produces_a_latin_slug(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.products.store'), [
                'slug' => '',
                'price_cents' => 1000,
                'is_active' => true,
                'translations' => [
                    'ar' => ['name' => 'عصير برتقال', 'description' => ''],
                    'en' => ['name' => '', 'description' => ''],
                ],
            ])
            ->assertRedirect();

        $slug = Product::query()->latest('id')->value('slug');

        $this->assertNotSame('', $slug);
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $slug);
    }

    public function test_two_products_with_the_same_name_get_distinct_slugs(): void
    {
        $payload = fn () => [
            'slug' => '',
            'price_cents' => 1000,
            'is_active' => true,
            'translations' => [
                'ar' => ['name' => 'قهوة', 'description' => ''],
                'en' => ['name' => '', 'description' => ''],
            ],
        ];

        $this->actingAs($this->admin())->post(route('admin.products.store'), $payload())->assertRedirect();
        $this->actingAs($this->admin())->post(route('admin.products.store'), $payload())->assertRedirect();

        $slugs = Product::query()->orderBy('id')->pluck('slug')->all();

        $this->assertCount(2, $slugs);
        $this->assertNotSame($slugs[0], $slugs[1]);
    }

    public function test_a_slug_typed_by_hand_is_kept(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.products.store'), [
                'slug' => 'house-blend',
                'price_cents' => 1000,
                'is_active' => true,
                'translations' => [
                    'ar' => ['name' => 'خلطة البيت', 'description' => ''],
                    'en' => ['name' => '', 'description' => ''],
                ],
            ])
            ->assertRedirect();

        $this->assertSame('house-blend', Product::query()->latest('id')->value('slug'));
    }

    /**
     * Sibling order only. The flat list the table sends back carries the
     * whole tree, and sort_order is handed out per parent so a nested
     * section never inherits a number from an unrelated branch.
     */
    public function test_categories_are_reordered_within_their_parent(): void
    {
        $menu = $this->menu();
        $drinks = $this->section($menu, null, 'مشروبات');
        $food = $this->section($menu, null, 'طعام');
        $hot = $this->section($menu, $drinks, 'ساخنة');
        $cold = $this->section($menu, $drinks, 'باردة');

        $this->actingAs($this->admin())
            ->patch(route('admin.categories.reorder'), [
                'order' => [$food->id, $drinks->id, $cold->id, $hot->id],
            ])
            ->assertRedirect();

        $this->assertSame(0, $food->fresh()->sort_order);
        $this->assertSame(1, $drinks->fresh()->sort_order);

        // Children are numbered from zero again inside their own parent.
        $this->assertSame(0, $cold->fresh()->sort_order);
        $this->assertSame(1, $hot->fresh()->sort_order);
    }

    public function test_menus_are_reordered(): void
    {
        $breakfast = $this->menu('إفطار');
        $dinner = $this->menu('عشاء');

        $this->actingAs($this->admin())
            ->patch(route('admin.menus.reorder'), ['order' => [$dinner->id, $breakfast->id]])
            ->assertRedirect();

        $this->assertSame(0, $dinner->fresh()->sort_order);
        $this->assertSame(1, $breakfast->fresh()->sort_order);
    }

    public function test_products_are_reordered(): void
    {
        $first = $this->product(['ar' => 'أول']);
        $second = $this->product(['ar' => 'ثاني']);
        $third = $this->product(['ar' => 'ثالث']);

        $this->actingAs($this->admin())
            ->patch(route('admin.products.reorder'), [
                'order' => [$third->id, $first->id, $second->id],
            ])
            ->assertRedirect();

        $this->assertSame(0, $third->fresh()->sort_order);
        $this->assertSame(1, $first->fresh()->sort_order);
        $this->assertSame(2, $second->fresh()->sort_order);
    }

    /**
     * sort_order is one sequence over the whole catalog, so numbering a
     * filtered subset from zero would move those products past everything
     * the filter was hiding. The screen hides the handles while a filter is
     * on; this is the half that cannot be bypassed.
     */
    public function test_a_partial_product_order_is_refused(): void
    {
        $first = $this->product(['ar' => 'أول']);
        $second = $this->product(['ar' => 'ثاني']);
        $this->product(['ar' => 'ثالث']);

        $this->actingAs($this->admin())
            ->patch(route('admin.products.reorder'), ['order' => [$second->id, $first->id]])
            ->assertSessionHasErrors('order');

        $this->assertSame(0, $first->fresh()->sort_order);
        $this->assertSame(0, $second->fresh()->sort_order);
    }

    public function test_variation_groups_are_reordered(): void
    {
        $size = ModifierGroup::create([
            'is_required' => true, 'min_select' => 1, 'max_select' => 1,
            'is_active' => true, 'sort_order' => 0,
            'ar' => ['name' => 'الحجم'], 'en' => ['name' => 'Size'],
        ]);
        $extras = ModifierGroup::create([
            'is_required' => false, 'min_select' => 0, 'max_select' => 3,
            'is_active' => true, 'sort_order' => 1,
            'ar' => ['name' => 'إضافات'], 'en' => ['name' => 'Extras'],
        ]);

        $this->actingAs($this->admin())
            ->patch(route('admin.modifier-groups.reorder'), ['order' => [$extras->id, $size->id]])
            ->assertRedirect();

        $this->assertSame(0, $extras->fresh()->sort_order);
        $this->assertSame(1, $size->fresh()->sort_order);
    }
}
