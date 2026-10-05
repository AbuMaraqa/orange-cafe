<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\ServiceCharge;
use App\Models\User;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What actually sold, by product and by section.
 *
 * The orders report says a table paid 85. This says the 85 was four coffees
 * and a sandwich — the figure that decides what gets ordered in on Monday
 * and what comes off the menu.
 *
 * Three ways of getting it wrong are each worth a test of their own, because
 * all three produce a plausible-looking number that is simply false:
 *
 *   - adding the modifier rows to the line's subtotal, which bills every
 *     extra twice (OrderItemWriter already folded them into the unit price);
 *   - counting service charges, which makes the café's best-selling
 *     "product" its seating policy;
 *   - counting unpaid or cancelled bills, which reports food nobody bought.
 */
class ProductSalesReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(LanguageSeeder::class);
        $this->seed(PermissionSeeder::class);

        $this->admin = User::create([
            'name' => 'مدير',
            'email' => 'admin'.fake()->unique()->numberBetween(1, 99999).'@test.local',
            'password' => 'password',
            'locale' => 'ar',
            'email_verified_at' => now(),
        ]);
        $this->admin->assignRole('admin');
    }

    private function product(string $name, int $priceCents): Product
    {
        $product = Product::create([
            'slug' => 'p-'.fake()->unique()->numberBetween(1, 999999),
            'price_cents' => $priceCents,
            'is_active' => true,
        ]);
        $product->fill(['ar' => ['name' => $name]])->save();

        return $product->refresh();
    }

    private function category(string $name): Category
    {
        $menu = Menu::firstOrCreate(
            ['slug' => 'main'],
            ['publish_status' => 'published', 'is_active' => true, 'sort_order' => 1]
        );

        $category = Category::create([
            'menu_id' => $menu->id,
            'slug' => 'c-'.fake()->unique()->numberBetween(1, 999999),
            'is_active' => true,
        ]);
        $category->fill(['ar' => ['name' => $name]])->save();

        return $category->refresh();
    }

    private function paidOrder(string $at = '2026-09-10 13:00:00'): Order
    {
        return Order::create([
            'reference' => 'R-'.fake()->unique()->numberBetween(1, 999999),
            'status' => 'served',
            'total_cents' => 0,
            'amount_paid_cents' => 0,
            'paid_at' => $at,
        ]);
    }

    /**
     * A sold line, written the way OrderItemWriter writes one: the unit
     * price already carries whatever modifiers were chosen.
     */
    private function line(Order $order, Product $product, int $quantity, int $unitPriceCents, ?Category $category = null): void
    {
        $menuItemId = null;

        if ($category) {
            $menuItemId = MenuItem::create([
                'menu_id' => $category->menu_id,
                'category_id' => $category->id,
                'product_id' => $product->id,
                'is_active' => true,
                'sort_order' => 1,
            ])->id;
        }

        $order->items()->create([
            'product_id' => $product->id,
            'menu_item_id' => $menuItemId,
            'product_name' => $product->name,
            'prints_to_kitchen' => true,
            'unit_price_cents' => $unitPriceCents,
            'quantity' => $quantity,
            'subtotal_cents' => $unitPriceCents * $quantity,
        ]);
    }

    /** @return array<string, mixed> */
    private function report(string $from = '2026-09-01', string $to = '2026-09-30'): array
    {
        $response = $this->actingAs($this->admin)->get(route('admin.accounting.reports', [
            'tab' => 'products', 'from' => $from, 'to' => $to,
        ]));

        $response->assertOk();

        return $response->viewData('page')['props']['products'];
    }

    // ---------------------------------------------------------------
    // The headline
    // ---------------------------------------------------------------

    public function test_it_ranks_products_by_how_many_sold(): void
    {
        $coffee = $this->product('قهوة', 500);
        $cake = $this->product('كيكة', 2000);

        $order = $this->paidOrder();
        $this->line($order, $coffee, 4, 500);
        $this->line($order, $cake, 1, 2000);

        $rows = $this->report()['rows'];

        $this->assertSame('قهوة', $rows[0]['name'], 'four beat one');
        $this->assertSame(4, $rows[0]['quantity']);
        $this->assertSame(2000, $rows[0]['gross_cents']);

        $this->assertSame('كيكة', $rows[1]['name']);
        $this->assertSame(1, $rows[1]['quantity']);
    }

    public function test_the_same_product_across_bills_is_one_row(): void
    {
        $coffee = $this->product('قهوة', 500);

        $this->line($this->paidOrder('2026-09-10 13:00:00'), $coffee, 3, 500);
        $this->line($this->paidOrder('2026-09-12 20:00:00'), $coffee, 2, 500);

        $rows = $this->report()['rows'];

        $this->assertCount(1, $rows);
        $this->assertSame(5, $rows[0]['quantity']);
        $this->assertSame(2500, $rows[0]['gross_cents']);
        $this->assertSame(2, $rows[0]['orders_count'], 'it appeared on two tables');
    }

    public function test_the_share_says_how_concentrated_the_trade_is(): void
    {
        $order = $this->paidOrder();
        $this->line($order, $this->product('قهوة', 500), 3, 500);   // 1500
        $this->line($order, $this->product('شاي', 500), 1, 500);    //  500

        $rows = collect($this->report()['rows'])->keyBy('name');

        $this->assertSame(75.0, $rows['قهوة']['share']);
        $this->assertSame(25.0, $rows['شاي']['share']);
    }

    // ---------------------------------------------------------------
    // The three ways to get the money wrong
    // ---------------------------------------------------------------

    /**
     * OrderItemWriter resolves variations and extras *into* the unit price
     * before multiplying by the quantity; the order_item_modifiers rows are
     * there so a receipt can print what was chosen. Summing them on top of
     * the subtotal charges every extra twice.
     */
    public function test_a_modifier_is_not_charged_twice(): void
    {
        $coffee = $this->product('قهوة', 500);
        $order = $this->paidOrder();

        // A large with an extra shot: 500 base + 200 size + 100 shot, already
        // resolved into the unit price the way the writer does it.
        $this->line($order, $coffee, 2, 800);

        $order->items()->first()->modifiers()->createMany([
            ['modifier_group_name' => 'الحجم', 'modifier_name' => 'كبير', 'price_cents' => 200],
            ['modifier_group_name' => 'إضافات', 'modifier_name' => 'شوت إضافي', 'price_cents' => 100],
        ]);

        $rows = $this->report()['rows'];

        $this->assertSame(1600, $rows[0]['gross_cents'], '2 × 800, not 2 × 800 plus the modifier rows again');
    }

    /**
     * A table held past two hours is an OrderItem with a service_charge_id
     * and no product behind it. Counted here, the best-selling "product"
     * becomes the seating policy.
     */
    public function test_a_service_charge_is_not_a_product(): void
    {
        $order = $this->paidOrder();
        $this->line($order, $this->product('قهوة', 500), 1, 500);

        $charge = ServiceCharge::create(['amount_cents' => 2000, 'is_active' => true]);
        $charge->fill(['ar' => ['name' => 'خدمة طاولة']])->save();

        $order->items()->create([
            'service_charge_id' => $charge->id,
            'product_name' => 'خدمة طاولة',
            'prints_to_kitchen' => false,
            'unit_price_cents' => 2000,
            'quantity' => 1,
            'subtotal_cents' => 2000,
        ]);

        $report = $this->report();

        $this->assertCount(1, $report['rows']);
        $this->assertSame('قهوة', $report['rows'][0]['name']);
        $this->assertSame(500, $report['totals']['gross_cents'], 'the 20.00 charge is money, but it is not a product');
    }

    public function test_an_unpaid_bill_has_not_sold_anything(): void
    {
        $coffee = $this->product('قهوة', 500);

        $open = Order::create([
            'reference' => 'R-'.fake()->unique()->numberBetween(1, 999999),
            'status' => 'served',
            'total_cents' => 500,
        ]);
        $this->line($open, $coffee, 9, 500);

        $this->assertSame(0, $this->report()['totals']['quantity']);
    }

    public function test_a_cancelled_bill_is_left_out(): void
    {
        $coffee = $this->product('قهوة', 500);
        $order = $this->paidOrder();
        $this->line($order, $coffee, 7, 500);
        $order->update(['status' => 'cancelled']);

        $this->assertSame(0, $this->report()['totals']['quantity']);
    }

    public function test_sales_outside_the_range_are_left_out(): void
    {
        $coffee = $this->product('قهوة', 500);

        $this->line($this->paidOrder('2026-08-31 23:00:00'), $coffee, 5, 500);
        $this->line($this->paidOrder('2026-09-10 13:00:00'), $coffee, 2, 500);

        $this->assertSame(2, $this->report('2026-09-01', '2026-09-30')['totals']['quantity']);
    }

    // ---------------------------------------------------------------
    // Sections
    // ---------------------------------------------------------------

    public function test_it_groups_sales_by_section(): void
    {
        $hot = $this->category('مشروبات ساخنة');
        $sweets = $this->category('حلويات');

        $order = $this->paidOrder();
        $this->line($order, $this->product('قهوة', 500), 4, 500, $hot);
        $this->line($order, $this->product('كيكة', 2000), 1, 2000, $sweets);

        $byCategory = collect($this->report()['by_category'])->keyBy('name');

        $this->assertSame(2000, $byCategory['مشروبات ساخنة']['gross_cents']);
        $this->assertSame(4, $byCategory['مشروبات ساخنة']['quantity']);
        $this->assertSame(2000, $byCategory['حلويات']['gross_cents']);
    }

    /**
     * A sale that disappears from a report because somebody tidied the menu
     * is a silent loss. It lands under a null section the screen renders as
     * "بدون قسم" instead.
     */
    public function test_a_sale_with_no_surviving_menu_entry_still_counts(): void
    {
        $order = $this->paidOrder();
        $this->line($order, $this->product('قهوة', 500), 3, 500, category: null);

        $report = $this->report();

        $this->assertSame(3, $report['totals']['quantity']);
        $this->assertSame(1, count($report['by_category']));
        $this->assertNull($report['by_category'][0]['name']);
    }

    /**
     * Deleting a product nulls product_id on the lines it sold. Two products
     * deleted since must stay two rows under the names they were sold as —
     * not one row carrying both products' sales under one of their names.
     */
    public function test_deleted_products_stay_apart_under_the_names_they_were_sold_as(): void
    {
        $order = $this->paidOrder();
        $this->line($order, $tea = $this->product('شاي', 400), 5, 400);
        $this->line($order, $cake = $this->product('كيكة', 2000), 1, 2000);

        $tea->delete();
        $cake->delete();

        $rows = collect($this->report()['rows'])->keyBy('name');

        $this->assertCount(2, $rows);
        $this->assertSame(5, $rows['شاي']['quantity']);
        $this->assertSame(1, $rows['كيكة']['quantity']);
        $this->assertSame(2000, $rows['كيكة']['gross_cents']);
    }

    /**
     * The two groupings are built by two queries and must add up to the same
     * money — otherwise there is no way to tell which one is lying.
     */
    public function test_the_sections_add_up_to_the_products(): void
    {
        $hot = $this->category('مشروبات ساخنة');
        $sweets = $this->category('حلويات');

        $order = $this->paidOrder();
        $this->line($order, $this->product('قهوة', 500), 4, 500, $hot);
        $this->line($order, $this->product('شاي', 300), 2, 300, $hot);
        $this->line($order, $this->product('كيكة', 2000), 1, 2000, $sweets);

        $report = $this->report();

        $this->assertSame(
            $report['totals']['gross_cents'],
            (int) collect($report['by_category'])->sum('gross_cents')
        );
        $this->assertSame(
            $report['totals']['quantity'],
            (int) collect($report['by_category'])->sum('quantity')
        );
    }

    // ---------------------------------------------------------------
    // Getting it off the screen
    // ---------------------------------------------------------------

    public function test_it_exports_as_a_spreadsheet(): void
    {
        $this->line($this->paidOrder(), $this->product('قهوة', 500), 4, 500);

        $response = $this->actingAs($this->admin)->get(route('admin.accounting.reports.export', [
            'tab' => 'products', 'from' => '2026-09-01', 'to' => '2026-09-30',
        ]));

        $response->assertOk();

        $csv = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'Excel reads a BOM-less UTF-8 file as mojibake');
        $this->assertStringContainsString('قهوة', $csv);
        $this->assertStringContainsString('20.00', $csv);
    }

    public function test_the_floor_cannot_read_the_sales_report(): void
    {
        $waiter = User::create([
            'name' => 'نادل',
            'email' => 'w'.fake()->unique()->numberBetween(1, 99999).'@test.local',
            'password' => 'password',
            'locale' => 'ar',
            'email_verified_at' => now(),
        ]);
        $waiter->assignRole('waiter');

        $this->actingAs($waiter)
            ->get(route('admin.accounting.reports', ['tab' => 'products']))
            ->assertForbidden();
    }
}
