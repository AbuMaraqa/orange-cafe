<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Printer;
use App\Models\RestaurantTable;
use App\Models\ServiceCharge;
use App\Models\User;
use App\Services\Accounting\Cashbox;
use App\Services\Accounting\DayCloser;
use App\Services\Accounting\DayReportPrinter;
use App\Settings\RestaurantSettings;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The day's cash box, on the till roll.
 *
 * What a manager does at close is count the drawer against a figure, and that
 * figure lived only on a screen — so the count happened with a phone propped
 * against the till, or off a number copied onto the back of a receipt.
 *
 * The page itself is asserted by rendering the Blade directly. Everything
 * between the template and the printer is the rasteriser — mPDF, pdftoppm,
 * ESC/POS — which needs poppler on the machine running the suite and answers
 * a different question anyway: whether bytes reached a printer, not whether
 * the right numbers are on the paper.
 */
class DayReportPrintsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(LanguageSeeder::class);
        $this->seed(PermissionSeeder::class);
    }

    private function manager(): User
    {
        $user = User::create([
            'name' => 'مدير',
            'email' => 'm'.fake()->unique()->numberBetween(1, 99999).'@test.local',
            'password' => 'password',
            'locale' => 'ar',
            'email_verified_at' => now(),
        ]);
        $user->assignRole('admin');

        return $user;
    }

    private function waiter(): User
    {
        $user = User::create([
            'name' => 'نادل',
            'email' => 'w'.fake()->unique()->numberBetween(1, 99999).'@test.local',
            'password' => 'password',
            'locale' => 'ar',
            'email_verified_at' => now(),
        ]);
        $user->assignRole('waiter');

        return $user;
    }

    private function paidOn(string $at, int $cents, ?string $method = null, array $attributes = []): Order
    {
        return Order::create([
            'reference' => 'R-'.fake()->unique()->numberBetween(1, 999999),
            'status' => 'served',
            'total_cents' => $cents,
            'amount_paid_cents' => $cents,
            'paid_at' => $at,
            // Read by the observer when it writes the payment — see
            // OrderObserver::recordTheDifference().
            'payment_method' => $method,
            ...$attributes,
        ]);
    }

    /** The page exactly as DayReportPrinter::render() composes it. */
    private function page(string $date, ?User $by = null): string
    {
        $day = app(Cashbox::class)->day($date);

        return view('pdf.day-report-thermal', [
            'day' => $day,
            'items' => app(Cashbox::class)->itemsSold($date),
            'orders' => app(Cashbox::class)->ordersCollected($date),
            'closing' => $day['closing'],
            'branding' => app(RestaurantSettings::class),
            'logoPath' => null,
            'currencySymbol' => '₪',
            'printedAt' => '2026-03-10 23:40',
            'printedBy' => $by?->name,
            'labels' => __('pdf'),
        ])->render();
    }

    // ---------------------------------------------------------------
    // What is on the paper
    // ---------------------------------------------------------------

    public function test_the_days_takings_and_expected_balance_are_on_it(): void
    {
        $this->paidOn('2026-03-10 13:00:00', 3000);
        $this->paidOn('2026-03-10 19:00:00', 4500);

        $page = $this->page('2026-03-10');

        $this->assertStringContainsString('2026-03-10', $page);
        $this->assertStringContainsString('75.00', $page, 'the two bills, collected');
        $this->assertStringContainsString('تحصيل الطلبات', $page);
        $this->assertStringContainsString('الرصيد المتوقع', $page);
    }

    public function test_the_opening_float_is_on_it(): void
    {
        // Counted high last night — none of which is this morning's float.
        $this->paidOn('2026-03-09 20:00:00', 2000);
        app(DayCloser::class)->close('2026-03-09', 52000, null, $this->manager());

        $this->paidOn('2026-03-10 13:00:00', 3000);

        $page = $this->page('2026-03-10');

        $this->assertStringContainsString('رصيد بداية اليوم', $page);
        $this->assertStringContainsString('500.00', $page, 'the float the day opened on');
        $this->assertStringContainsString('530.00', $page, 'and the balance it produces');
        $this->assertStringNotContainsString('520.00', $page, 'not last night’s count');
    }

    /**
     * A report pulled mid-shift is legitimate — a manager checking before
     * going home — and must not be mistaken afterwards for the signed-off
     * figure.
     */
    public function test_an_unclosed_day_says_so_on_its_face(): void
    {
        $this->paidOn('2026-03-10 13:00:00', 3000);

        $page = $this->page('2026-03-10');

        $this->assertStringContainsString('أرقام غير نهائية', $page);
        $this->assertStringNotContainsString('الرصيد المعدود', $page, 'nobody has counted it yet');
    }

    public function test_a_closed_day_carries_the_counted_figure_and_the_gap(): void
    {
        $this->paidOn('2026-03-10 13:00:00', 3000);
        // Expected is the 500 float plus the 30 taken.
        app(DayCloser::class)->close('2026-03-10', 52900, 'ناقص شيكل', $this->manager());

        $page = $this->page('2026-03-10');

        $this->assertStringContainsString('الرصيد المعدود', $page);
        $this->assertStringContainsString('529.00', $page);
        $this->assertStringContainsString('عجز', $page, 'a shortfall is named as one');
        $this->assertStringContainsString('ناقص شيكل', $page, 'the note the manager left');
        $this->assertStringNotContainsString('أرقام غير نهائية', $page);
    }

    public function test_a_surplus_is_named_as_a_surplus(): void
    {
        $this->paidOn('2026-03-10 13:00:00', 3000);
        app(DayCloser::class)->close('2026-03-10', 53100, null, $this->manager());

        $this->assertStringContainsString('زيادة', $this->page('2026-03-10'));
    }

    /**
     * The rule the whole cash box rests on, carried onto the paper: figures
     * that are shown but never subtracted have to be labelled, or somebody
     * subtracts them by hand.
     */
    public function test_figures_that_are_not_in_the_drawer_are_labelled(): void
    {
        $order = $this->paidOn('2026-03-10 13:00:00', 3000);
        $order->update(['discount_cents' => 500]);

        $page = $this->page('2026-03-10');

        $this->assertStringContainsString('خصومات الفواتير', $page);
        $this->assertStringContainsString('لا تُطرح من الصندوق', $page);
    }

    public function test_it_names_who_printed_it(): void
    {
        $this->assertStringContainsString('مدير', $this->page('2026-03-10', $this->manager()));
    }

    /**
     * A settled bill with lines on it.
     *
     * @param  array<string, array{int, int}>  $lines  name => [quantity, unit price]
     */
    private function soldOn(string $at, array $lines, int $discountCents = 0): Order
    {
        $subtotal = array_sum(array_map(fn (array $line) => $line[0] * $line[1], $lines));

        $order = $this->paidOn($at, $subtotal - $discountCents);
        $order->forceFill(['discount_cents' => $discountCents])->save();

        foreach ($lines as $name => [$quantity, $unitPrice]) {
            $order->items()->create([
                'product_name' => $name,
                'unit_price_cents' => $unitPrice,
                'quantity' => $quantity,
                'subtotal_cents' => $quantity * $unitPrice,
            ]);
        }

        return $order;
    }

    // ---------------------------------------------------------------
    // What was sold
    // ---------------------------------------------------------------

    /**
     * The second half of the question a manager asks at close. The drawer
     * says how much; this says on what.
     */
    public function test_every_item_sold_is_on_the_paper_with_its_quantity(): void
    {
        $this->soldOn('2026-03-10 13:00:00', ['شاي' => [3, 500], 'قهوة' => [1, 800]]);
        $this->soldOn('2026-03-10 19:00:00', ['شاي' => [2, 500]]);

        $page = $this->page('2026-03-10');

        $this->assertStringContainsString('الأصناف المباعة', $page);
        // The same dish on two bills is one line with the night's quantity on
        // it — a manager counting teas should not have to add up the list.
        $this->assertStringContainsString('5× شاي', $page);
        $this->assertStringContainsString('25.00', $page, 'what the teas brought in');
        $this->assertStringContainsString('1× قهوة', $page);
        $this->assertStringContainsString('إجمالي الكميات', $page);
    }

    public function test_the_discount_sits_between_what_was_rung_up_and_what_was_owed(): void
    {
        $this->soldOn('2026-03-10 13:00:00', ['شاي' => [4, 500]], discountCents: 500);

        $page = $this->page('2026-03-10');

        $this->assertStringContainsString('إجمالي المبيعات', $page);
        $this->assertStringContainsString('20.00', $page, 'the lines, before the discount');
        $this->assertStringContainsString('خصم على الفواتير', $page);
        $this->assertStringContainsString('صافي المبيعات', $page);
        $this->assertStringContainsString('15.00', $page, 'and what the bill actually came to');
    }

    /**
     * Card trade is trade. The drawer never saw it, and the list of what was
     * sold does not care — a night that went half on card must not print a
     * sales list with half the night missing from it.
     */
    public function test_a_bill_settled_on_a_card_still_appears_in_what_was_sold(): void
    {
        $order = $this->soldOn('2026-03-10 13:00:00', ['كنافة' => [2, 1500]]);
        $order->payments()->update(['method' => OrderPayment::METHOD_CARD]);

        $page = $this->page('2026-03-10');

        $this->assertStringContainsString('2× كنافة', $page);
        $this->assertStringContainsString('30.00', $page);
    }

    /**
     * Money on the bill with nothing behind it. It is trade, so it is on the
     * list and in the total — but nobody carried it to a table, so it is not
     * counted among the things served, and it does not get to sit at the top
     * of a list headed "sold" looking like the night's best seller.
     */
    public function test_a_service_charge_is_listed_without_being_counted_as_something_served(): void
    {
        $order = $this->soldOn('2026-03-10 13:00:00', ['شاي' => [2, 500]]);

        $charge = ServiceCharge::create(['amount_cents' => 1000, 'is_active' => true]);
        $charge->fill(['ar' => ['name' => 'رسوم خدمة']])->save();

        $order->items()->create([
            'service_charge_id' => $charge->id,
            'product_name' => 'رسوم خدمة',
            'prints_to_kitchen' => false,
            'unit_price_cents' => 1000,
            'quantity' => 1,
            'subtotal_cents' => 1000,
        ]);
        $order->recalculateTotal();

        $sold = app(Cashbox::class)->itemsSold('2026-03-10');

        $this->assertSame(2, $sold['quantity'], 'two teas were served; the charge was not');
        $this->assertSame(2000, $sold['gross_cents'], 'but its money is in the takings');
        $this->assertSame('رسوم خدمة', end($sold['lines'])['name'], 'and it sits at the bottom');

        $this->assertStringContainsString('رسوم خدمة', $this->page('2026-03-10'));
    }

    /**
     * A table still sitting there has not sold anything yet. Its dishes land
     * on the day its bill is settled — the same day its discount lands, which
     * is what keeps the two from disagreeing.
     */
    public function test_a_table_that_has_not_paid_is_not_in_the_days_sales(): void
    {
        $this->soldOn('2026-03-10 13:00:00', ['شاي' => [1, 500]]);

        $open = Order::create([
            'reference' => 'R-open',
            'status' => 'served',
            'total_cents' => 4000,
        ]);
        $open->items()->create([
            'product_name' => 'مشاوي',
            'unit_price_cents' => 4000,
            'quantity' => 1,
            'subtotal_cents' => 4000,
        ]);

        $page = $this->page('2026-03-10');

        $this->assertStringNotContainsString('مشاوي', $page);
        $this->assertStringContainsString('1× شاي', $page);
    }

    // ---------------------------------------------------------------
    // Every bill, and how it was paid
    // ---------------------------------------------------------------

    public function test_every_bill_paid_is_on_the_paper_with_how_it_was_paid(): void
    {
        $table = RestaurantTable::create(['label' => 'طاولة 7']);

        $cash = $this->paidOn('2026-03-10 13:05:00', 3000, attributes: ['table_id' => $table->id]);
        $card = $this->paidOn('2026-03-10 19:40:00', 4500, OrderPayment::METHOD_CARD, ['payment_reference' => 'TX-551']);

        $page = $this->page('2026-03-10');

        $this->assertStringContainsString('تفاصيل الطلبات (2)', $page);

        // The number printed on the customer's receipt, so a bill on this
        // list can be found again.
        $this->assertStringContainsString('#'.$cash->reference, $page);
        $this->assertStringContainsString('13:05 · طاولة 7', $page);
        $this->assertStringContainsString('نقداً', $page);

        $this->assertStringContainsString('#'.$card->reference, $page);
        $this->assertStringContainsString('19:40', $page);
        $this->assertStringContainsString('فيزا / بطاقة', $page);
        // The terminal's number, so the card slip can be matched line by line.
        $this->assertStringContainsString('TX-551', $page);

        $this->assertStringContainsString('المجموع حسب طريقة الدفع', $page);
        $this->assertStringContainsString('إجمالي المحصّل', $page);
        $this->assertStringContainsString('75.00', $page, 'both bills, together');
    }

    /**
     * The point of reading it off the payments: the totals under the list
     * are the cash box's own figures, so the bottom of the paper and the top
     * of it cannot disagree.
     */
    public function test_the_totals_under_the_list_are_the_drawers_own_figures(): void
    {
        $this->paidOn('2026-03-10 12:00:00', 3000);
        $this->paidOn('2026-03-10 14:00:00', 2200, OrderPayment::METHOD_CARD);
        $this->paidOn('2026-03-10 21:00:00', 1800);

        // Neither of these is today's money.
        $this->paidOn('2026-03-09 21:00:00', 9900);
        $this->paidOn('2026-03-10 16:00:00', 7700, attributes: ['status' => 'cancelled']);

        $cashbox = app(Cashbox::class);
        $day = $cashbox->day('2026-03-10');
        $orders = $cashbox->ordersCollected('2026-03-10');

        $this->assertCount(3, $orders['lines']);
        $this->assertSame($day['collected_cents'], $orders['by_method'][OrderPayment::METHOD_CASH]);
        $this->assertSame($day['card_collected_cents'], $orders['by_method'][OrderPayment::METHOD_CARD]);
        $this->assertSame($day['total_takings_cents'], $orders['total_cents']);
        $this->assertSame(7000, $orders['total_cents']);

        $this->assertSame(
            ['12:00', '14:00', '21:00'],
            array_map(fn (array $line) => $line['at']->format('H:i'), $orders['lines']),
            'in the order they were paid',
        );
    }

    /**
     * Half in cash, half on a card. "Split" alone cannot be checked against
     * anything, so the paper says how much went which way.
     */
    public function test_a_bill_paid_two_ways_shows_both(): void
    {
        $order = $this->paidOn('2026-03-10 20:00:00', 4500);
        $order->payments()->update(['amount_cents' => 2000]);
        $order->recordPayment(2500, at: Carbon::parse('2026-03-10 20:01:00'), method: OrderPayment::METHOD_CARD);

        $line = app(Cashbox::class)->ordersCollected('2026-03-10')['lines'][0];

        $this->assertNull($line['method']);
        $this->assertSame([OrderPayment::METHOD_CASH => 2000, OrderPayment::METHOD_CARD => 2500], $line['by_method']);
        $this->assertSame(4500, $line['total_cents']);

        $page = $this->page('2026-03-10');

        $this->assertStringContainsString('مختلط', $page);
        $this->assertStringContainsString('20.00', $page);
        $this->assertStringContainsString('25.00', $page);
        $this->assertStringContainsString('45.00', $page);
    }

    /**
     * A table still sitting there that put something down tonight. That money
     * is in the drawer, so it is on the list — but marked, or a 15.00 line
     * reads as a 15.00 bill.
     */
    public function test_a_part_payment_on_an_open_table_is_marked_as_one(): void
    {
        $open = Order::create([
            'status' => 'served',
            'total_cents' => 4000,
        ]);
        $open->recordPayment(1500, at: Carbon::parse('2026-03-10 18:00:00'));

        $line = app(Cashbox::class)->ordersCollected('2026-03-10')['lines'][0];

        $this->assertTrue($line['is_part_payment']);
        $this->assertSame(1500, $line['total_cents']);

        $page = $this->page('2026-03-10');

        $this->assertStringContainsString('#'.$open->reference, $page);
        $this->assertStringContainsString('دفعة جزئية', $page);
    }

    public function test_a_bill_paid_in_one_go_is_not_called_a_part_payment(): void
    {
        $this->paidOn('2026-03-10 13:00:00', 3000);

        $this->assertFalse(app(Cashbox::class)->ordersCollected('2026-03-10')['lines'][0]['is_part_payment']);
        $this->assertStringNotContainsString('دفعة جزئية', $this->page('2026-03-10'));
    }

    public function test_a_day_nobody_paid_says_so_rather_than_printing_zeros(): void
    {
        $page = $this->page('2026-03-10');

        $this->assertStringContainsString('لم يُدفع أي طلب في هذا اليوم', $page);
        $this->assertStringNotContainsString('المجموع حسب طريقة الدفع', $page);
    }

    // ---------------------------------------------------------------
    // Getting it to a printer
    // ---------------------------------------------------------------

    public function test_with_no_till_printer_it_reports_the_reason_rather_than_succeeding(): void
    {
        $printer = app(DayReportPrinter::class);

        $this->assertSame(0, $printer->print('2026-03-10', $this->manager()));
        $this->assertNotSame('', $printer->lastError(), 'a silent zero is how a cashier ends up hunting for paper');
    }

    // ---------------------------------------------------------------
    // Who may ask for it
    // ---------------------------------------------------------------

    public function test_the_floor_cannot_print_the_cash_box(): void
    {
        // Same door as reading the cash box itself: a waiter has no business
        // with the day's takings on paper any more than on screen.
        $this->actingAs($this->waiter())
            ->post(route('admin.accounting.cashbox.print'), ['date' => '2026-03-10'])
            ->assertForbidden();
    }

    /**
     * Not even the till, which is the answer that surprises people.
     *
     * config/permissions.php gives the cashier role no accounting permission
     * at all — "the money side of the business is the owner's, and the till
     * is not a window into it" — which already puts the nightly close with an
     * admin. A printout of the day's takings is the artefact most likely to
     * walk that rule out of the room, so it stays behind the same door as the
     * screen it came from.
     */
    public function test_not_even_the_till_can_print_the_days_takings(): void
    {
        $cashier = User::create([
            'name' => 'كاشير',
            'email' => 'c'.fake()->unique()->numberBetween(1, 99999).'@test.local',
            'password' => 'password',
            'locale' => 'ar',
            'email_verified_at' => now(),
        ]);
        $cashier->assignRole('cashier');

        $this->assertFalse($cashier->can('cashbox.view'), 'the till does not read the cash box');

        $this->actingAs($cashier)
            ->post(route('admin.accounting.cashbox.print'), ['date' => '2026-03-10'])
            ->assertForbidden();
    }

    public function test_the_owner_can(): void
    {
        Printer::create([
            'name' => 'طابعة الكاشير',
            'station' => 'cashier',
            'connection_type' => 'network',
            'ip_address' => '192.168.1.90',
            'port' => 9100,
            'is_active' => true,
        ]);

        // Not assertOk: it redirects back either way, and whether bytes
        // reached a printer that is not on this network is a different
        // question. What this asserts is that the door opened.
        $this->actingAs($this->manager())
            ->post(route('admin.accounting.cashbox.print'), ['date' => '2026-03-10'])
            ->assertRedirect();
    }
}
