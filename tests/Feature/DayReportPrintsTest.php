<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Printer;
use App\Models\User;
use App\Services\Accounting\Cashbox;
use App\Services\Accounting\DayCloser;
use App\Services\Accounting\DayReportPrinter;
use App\Settings\RestaurantSettings;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private function paidOn(string $at, int $cents): Order
    {
        return Order::create([
            'reference' => 'R-'.fake()->unique()->numberBetween(1, 999999),
            'status' => 'served',
            'total_cents' => $cents,
            'amount_paid_cents' => $cents,
            'paid_at' => $at,
        ]);
    }

    /** The page exactly as DayReportPrinter::render() composes it. */
    private function page(string $date, ?User $by = null): string
    {
        $day = app(Cashbox::class)->day($date);

        return view('pdf.day-report-thermal', [
            'day' => $day,
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

    public function test_the_opening_float_carried_from_last_night_is_on_it(): void
    {
        $this->paidOn('2026-03-09 20:00:00', 2000);
        app(DayCloser::class)->close('2026-03-09', 2000, null, $this->manager());

        $this->paidOn('2026-03-10 13:00:00', 3000);

        $page = $this->page('2026-03-10');

        $this->assertStringContainsString('رصيد بداية اليوم', $page);
        $this->assertStringContainsString('20.00', $page, 'last night’s counted figure');
        $this->assertStringContainsString('50.00', $page, 'and the balance it produces');
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
        app(DayCloser::class)->close('2026-03-10', 2900, 'ناقص شيكل', $this->manager());

        $page = $this->page('2026-03-10');

        $this->assertStringContainsString('الرصيد المعدود', $page);
        $this->assertStringContainsString('29.00', $page);
        $this->assertStringContainsString('عجز', $page, 'a shortfall is named as one');
        $this->assertStringContainsString('ناقص شيكل', $page, 'the note the manager left');
        $this->assertStringNotContainsString('أرقام غير نهائية', $page);
    }

    public function test_a_surplus_is_named_as_a_surplus(): void
    {
        $this->paidOn('2026-03-10 13:00:00', 3000);
        app(DayCloser::class)->close('2026-03-10', 3100, null, $this->manager());

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
