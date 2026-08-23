<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Order;
use App\Models\Product;
use App\Services\MenuCatalogPresenter;
use App\Services\OrderItemWriter;
use Database\Seeders\LanguageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * A dish sold in sizes has no single price, and the size chosen *is* the
 * price rather than a surcharge on top of one.
 *
 * Before variations existed there were only two ways to model a burger
 * sold in one size, and the cafe hit both:
 *
 *   - give the product a base price of 30 and the size a price of 30, and
 *     the card advertises 30 while the basket charges 60, because the size
 *     was added to a base that was already a size;
 *   - give the product a base price of 0 so the sizes stand alone, and the
 *     menu advertises the burger at zero.
 *
 * These tests pin the third way: exactly one choice, its price replaces the
 * base, and the card quotes the top of the range.
 */
class VariationPricingTest extends TestCase
{
    use RefreshDatabase;

    private function menuItemFor(Product $product): MenuItem
    {
        $menu = Menu::create([
            'slug' => 'test-menu',
            'publish_status' => 'published',
            'is_active' => true,
            'sort_order' => 0,
            'ar' => ['name' => 'القائمة'],
            'en' => ['name' => 'Menu'],
        ]);

        $category = Category::create([
            'menu_id' => $menu->id,
            'slug' => 'burgers-'.fake()->unique()->numberBetween(1, 99999),
            'is_active' => true,
            'sort_order' => 0,
            'station' => 'kitchen',
            'ar' => ['name' => 'برجر'],
            'en' => ['name' => 'Burgers'],
        ]);

        return MenuItem::create([
            'menu_id' => $menu->id,
            'category_id' => $category->id,
            'product_id' => $product->id,
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    private function product(string $name, int $baseCents): Product
    {
        return Product::create([
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'price_cents' => $baseCents,
            'is_active' => true,
            'sort_order' => 0,
            'ar' => ['name' => $name],
            'en' => ['name' => $name],
        ]);
    }

    /**
     * @param  array<string, int>  $options  name => price in cents
     */
    private function group(Product $product, string $name, array $options, bool $isVariation): ModifierGroup
    {
        $group = ModifierGroup::create([
            'is_required' => $isVariation,
            'is_variation' => $isVariation,
            'min_select' => $isVariation ? 1 : 0,
            'max_select' => $isVariation ? 1 : null,
            'is_active' => true,
            'sort_order' => 0,
            'ar' => ['name' => $name],
            'en' => ['name' => $name],
        ]);

        foreach ($options as $optionName => $cents) {
            Modifier::create([
                'modifier_group_id' => $group->id,
                'price_cents' => $cents,
                'is_active' => true,
                'sort_order' => 0,
                'ar' => ['name' => $optionName],
                'en' => ['name' => $optionName],
            ]);
        }

        $product->modifierGroups()->attach($group->id, ['sort_order' => 0]);

        return $group->fresh('modifiers');
    }

    private function order(): Order
    {
        return Order::create(['status' => 'pending', 'locale' => 'ar', 'total_cents' => 0]);
    }

    private function freshProduct(Product $product): Product
    {
        return Product::with('modifierGroups.modifiers', 'modifierOverrides')->findOrFail($product->id);
    }

    // -----------------------------------------------------------------
    // The bug itself
    // -----------------------------------------------------------------

    public function test_a_size_replaces_the_base_price_instead_of_adding_to_it(): void
    {
        $burger = $this->product('برجر دجاج طازج', 3000);
        $this->group($burger, 'الحجم', ['150 غم' => 3000, '250 غم' => 4500], isVariation: true);
        $menuItem = $this->menuItemFor($burger);

        $order = $this->order();
        $small = Modifier::query()->whereHas('translations', fn ($q) => $q->where('name', '150 غم'))->sole();

        app(OrderItemWriter::class)->addLines($order, [[
            'menu_item_id' => $menuItem->id,
            'quantity' => 1,
            'modifier_ids' => [$small->id],
        ]]);

        // 30, not 60. The base and the size are the same number here on
        // purpose — that is the shape the cafe actually had, and adding
        // them is what produced the doubled price.
        $this->assertSame(3000, $order->items()->sole()->unit_price_cents);
    }

    public function test_a_bigger_size_costs_its_own_price_and_not_a_sum(): void
    {
        $burger = $this->product('برجر دجاج طازج', 3000);
        $this->group($burger, 'الحجم', ['150 غم' => 3000, '250 غم' => 4500], isVariation: true);
        $menuItem = $this->menuItemFor($burger);

        $large = Modifier::query()->whereHas('translations', fn ($q) => $q->where('name', '250 غم'))->sole();

        $order = $this->order();
        app(OrderItemWriter::class)->addLines($order, [[
            'menu_item_id' => $menuItem->id,
            'quantity' => 1,
            'modifier_ids' => [$large->id],
        ]]);

        $this->assertSame(4500, $order->items()->sole()->unit_price_cents);
    }

    // -----------------------------------------------------------------
    // Extras still add — the rule that had to survive
    // -----------------------------------------------------------------

    public function test_an_extra_still_adds_on_top_of_the_chosen_size(): void
    {
        $burger = $this->product('برجر دجاج طازج', 3000);
        $this->group($burger, 'الحجم', ['150 غم' => 3000, '250 غم' => 4500], isVariation: true);
        $this->group($burger, 'إضافات', ['جبنة' => 500], isVariation: false);
        $menuItem = $this->menuItemFor($burger);

        $large = Modifier::query()->whereHas('translations', fn ($q) => $q->where('name', '250 غم'))->sole();
        $cheese = Modifier::query()->whereHas('translations', fn ($q) => $q->where('name', 'جبنة'))->sole();

        $order = $this->order();
        app(OrderItemWriter::class)->addLines($order, [[
            'menu_item_id' => $menuItem->id,
            'quantity' => 1,
            'modifier_ids' => [$large->id, $cheese->id],
        ]]);

        $this->assertSame(5000, $order->items()->sole()->unit_price_cents, '45 for the size + 5 for the cheese.');
    }

    public function test_a_product_with_no_variation_prices_exactly_as_before(): void
    {
        $juice = $this->product('عصير', 1500);
        $this->group($juice, 'إضافات', ['نعناع' => 300], isVariation: false);
        $menuItem = $this->menuItemFor($juice);

        $mint = Modifier::query()->whereHas('translations', fn ($q) => $q->where('name', 'نعناع'))->sole();

        $order = $this->order();
        app(OrderItemWriter::class)->addLines($order, [[
            'menu_item_id' => $menuItem->id,
            'quantity' => 1,
            'modifier_ids' => [$mint->id],
        ]]);

        $this->assertSame(1800, $order->items()->sole()->unit_price_cents);
    }

    // -----------------------------------------------------------------
    // The menu card
    // -----------------------------------------------------------------

    public function test_the_menu_card_quotes_the_highest_size_and_never_zero(): void
    {
        $this->seed(LanguageSeeder::class);

        // Base price of zero — the workaround a cafe reaches for when the
        // sizes have to stand alone, and the reason the card read "0.00".
        $burger = $this->product('برجر دجاج طازج', 0);
        $this->group($burger, 'الحجم', ['150 غم' => 3000, '250 غم' => 4500], isVariation: true);
        $this->menuItemFor($burger);

        $payload = app(MenuCatalogPresenter::class)->visibleMenusPayload();
        $card = collect($payload)->firstOrFail()['categories'][0]['products'][0];

        $this->assertSame(4500, $card['price_cents'], 'The card quotes the top of the range.');
        $this->assertSame(0, $card['base_price_cents'], 'The raw base is still sent, for the client to price extras from.');
        $this->assertTrue($card['modifier_groups'][0]['is_variation']);
    }

    public function test_the_card_falls_back_rather_than_advertising_zero(): void
    {
        $this->seed(LanguageSeeder::class);

        $burger = $this->product('برجر', 2500);
        $group = $this->group($burger, 'الحجم', ['150 غم' => 3000], isVariation: true);
        // Every size switched off — the group prices nothing at all.
        $group->modifiers()->update(['is_active' => false]);
        $this->menuItemFor($burger);

        $payload = app(MenuCatalogPresenter::class)->visibleMenusPayload();
        $card = collect($payload)->firstOrFail()['categories'][0]['products'][0];

        $this->assertSame(2500, $card['price_cents'], 'Falls back to the base rather than quoting nothing.');
    }

    // -----------------------------------------------------------------
    // A size is not optional
    // -----------------------------------------------------------------

    public function test_ordering_a_sized_dish_without_a_size_is_refused(): void
    {
        $burger = $this->product('برجر دجاج طازج', 3000);
        $this->group($burger, 'الحجم', ['150 غم' => 3000], isVariation: true);
        $menuItem = $this->menuItemFor($burger);

        $this->expectException(ValidationException::class);

        app(OrderItemWriter::class)->addLines($this->order(), [[
            'menu_item_id' => $menuItem->id,
            'quantity' => 1,
            'modifier_ids' => [],
        ]]);
    }

    public function test_two_sizes_at_once_are_refused(): void
    {
        $burger = $this->product('برجر دجاج طازج', 3000);
        $this->group($burger, 'الحجم', ['150 غم' => 3000, '250 غم' => 4500], isVariation: true);
        $menuItem = $this->menuItemFor($burger);

        $ids = Modifier::query()->pluck('id')->all();

        $this->expectException(ValidationException::class);

        app(OrderItemWriter::class)->addLines($this->order(), [[
            'menu_item_id' => $menuItem->id,
            'quantity' => 1,
            'modifier_ids' => $ids,
        ]]);
    }
}
