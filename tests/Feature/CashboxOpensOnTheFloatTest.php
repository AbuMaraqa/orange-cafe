<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\Accounting\Cashbox;
use App\Services\Accounting\DayCloser;
use App\Settings\RestaurantSettings;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsCashboxFloat;
use Tests\TestCase;

/**
 * The owner's routine, as the cash box has to follow it: the till opens by
 * itself on a fixed float — 500 unless the settings say otherwise — and the
 * one thing anybody does with it is close it at night, take everything above
 * the float, and leave the float for the morning.
 *
 * So a morning never inherits a night. What was counted, what was short and
 * what was never closed all stay on the night they belong to.
 */
class CashboxOpensOnTheFloatTest extends TestCase
{
    use RefreshDatabase, SetsCashboxFloat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(LanguageSeeder::class);
        $this->seed(PermissionSeeder::class);
    }

    private function cashbox(): Cashbox
    {
        return app(Cashbox::class);
    }

    private function owner(): User
    {
        $user = User::create([
            'name' => 'صاحب المحل',
            'email' => 'o'.fake()->unique()->numberBetween(1, 99999).'@test.local',
            'password' => 'password',
            'locale' => 'ar',
            'email_verified_at' => now(),
        ]);
        $user->assignRole('admin');

        return $user;
    }

    private function paidOn(string $dateTime, int $cents): Order
    {
        return Order::create([
            'reference' => 'R-'.fake()->unique()->numberBetween(1, 999999),
            'status' => 'served',
            'total_cents' => $cents,
            'amount_paid_cents' => $cents,
            'paid_at' => $dateTime,
        ]);
    }

    // ---------------------------------------------------------------
    // The morning
    // ---------------------------------------------------------------

    public function test_a_day_opens_on_500_without_anybody_opening_it(): void
    {
        $this->assertSame(self::DEFAULT_FLOAT_CENTS, $this->cashbox()->openingCents('2026-03-10'));
        $this->assertSame(self::DEFAULT_FLOAT_CENTS, $this->cashbox()->day('2026-03-10')['balance_cents']);
    }

    public function test_the_float_is_whatever_the_settings_say(): void
    {
        $this->floatOf(30000);

        $this->assertSame(30000, $this->cashbox()->openingCents('2026-03-10'));
    }

    public function test_a_float_of_nothing_opens_on_nothing(): void
    {
        $this->floatOf(0);

        $this->assertSame(0, $this->cashbox()->openingCents('2026-03-10'));
    }

    /**
     * The takings sit on top of the float, and closing is measured against
     * both — the float is in the drawer being counted.
     */
    public function test_the_night_is_counted_against_the_float_plus_the_takings(): void
    {
        $this->paidOn('2026-03-10 20:00:00', 134000);

        $closing = app(DayCloser::class)->close('2026-03-10', 184000, null, $this->owner());

        $this->assertSame(self::DEFAULT_FLOAT_CENTS, $closing->opening_cents);
        $this->assertSame(184000, $closing->expected_cents);
        $this->assertSame(0, $closing->difference_cents);
    }

    // ---------------------------------------------------------------
    // A morning never inherits a night
    // ---------------------------------------------------------------

    /**
     * 1,840 was counted and 1,340 of it went home with the owner. The next
     * day opened on 1,840 when the cash box carried the count forward.
     */
    public function test_the_morning_after_a_close_is_the_float_not_the_count(): void
    {
        $this->paidOn('2026-03-10 20:00:00', 134000);
        app(DayCloser::class)->close('2026-03-10', 184000, null, $this->owner());

        $this->assertSame(self::DEFAULT_FLOAT_CENTS, $this->cashbox()->openingCents('2026-03-11'));
    }

    /**
     * A shortfall is on the record of the night it happened — the closing
     * names it — and the owner tops the drawer back up to the float.
     */
    public function test_a_short_night_stays_on_its_own_closing(): void
    {
        $this->paidOn('2026-03-10 20:00:00', 10000);
        app(DayCloser::class)->close('2026-03-10', 59800, 'ناقص 2', $this->owner());

        $this->assertSame(-200, $this->cashbox()->closingFor('2026-03-10')->difference_cents);
        $this->assertSame(self::DEFAULT_FLOAT_CENTS, $this->cashbox()->openingCents('2026-03-11'));
    }

    /**
     * The trade this rule makes, pinned so it is a decision and not an
     * accident: a night nobody closed is not added to the next morning. It
     * stays open, with its own figures, waiting to be signed off.
     */
    public function test_a_night_nobody_closed_is_not_carried(): void
    {
        $this->paidOn('2026-03-10 20:00:00', 3000);

        $this->assertSame(self::DEFAULT_FLOAT_CENTS, $this->cashbox()->openingCents('2026-03-11'));

        $this->assertFalse($this->cashbox()->isClosed('2026-03-10'));
        $this->assertSame(self::DEFAULT_FLOAT_CENTS + 3000, $this->cashbox()->day('2026-03-10')['balance_cents']);
    }

    public function test_a_long_history_with_no_close_still_opens_on_the_float(): void
    {
        $this->paidOn('2026-03-01 20:00:00', 16200);
        $this->paidOn('2026-03-02 20:00:00', 11400);
        $this->paidOn('2026-03-03 20:00:00', 12500);

        $this->assertSame(self::DEFAULT_FLOAT_CENTS, $this->cashbox()->openingCents('2026-03-04'));
    }

    public function test_reopening_a_night_leaves_the_next_morning_alone(): void
    {
        $owner = $this->owner();

        $this->paidOn('2026-03-10 20:00:00', 5000);
        app(DayCloser::class)->close('2026-03-10', 54800, 'ناقص 2', $owner);
        app(DayCloser::class)->reopen('2026-03-10', $owner);

        $this->assertFalse($this->cashbox()->isClosed('2026-03-10'));
        $this->assertSame(self::DEFAULT_FLOAT_CENTS, $this->cashbox()->openingCents('2026-03-11'));
    }

    // ---------------------------------------------------------------
    // Changing the float
    // ---------------------------------------------------------------

    /**
     * A closed night was signed against the float it opened on. Raising the
     * float later reaches the days still open and leaves that signature alone.
     */
    public function test_a_closed_night_keeps_the_float_it_was_signed_against(): void
    {
        $this->paidOn('2026-03-10 20:00:00', 10000);
        app(DayCloser::class)->close('2026-03-10', 60000, null, $this->owner());

        $this->floatOf(70000);

        $closing = $this->cashbox()->closingFor('2026-03-10');

        $this->assertSame(50000, $closing->opening_cents);
        $this->assertSame(60000, $closing->expected_cents);
        $this->assertSame(0, $closing->difference_cents);
        $this->assertSame(70000, $this->cashbox()->openingCents('2026-03-11'));
    }

    public function test_the_owner_sets_the_float_from_the_settings_page(): void
    {
        $this->actingAs($this->owner())
            ->post(route('admin.settings.update'), [
                'name' => 'Orange',
                'primary_color' => '#111827',
                'secondary_color' => '#f59e0b',
                'font_family' => 'tajawal',
                'currency_code' => 'ILS',
                'currency_symbol' => '₪',
                'business_day_starts_at' => '00:00',
                'cashbox_float' => '750',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        app()->forgetInstance(RestaurantSettings::class);
        app()->forgetInstance(Cashbox::class);

        $this->assertSame(75000, app(RestaurantSettings::class)->cashbox_float_cents);
        $this->assertSame(75000, $this->cashbox()->openingCents('2026-03-10'));
    }

    public function test_a_float_below_zero_is_refused(): void
    {
        $this->actingAs($this->owner())
            ->post(route('admin.settings.update'), [
                'name' => 'Orange',
                'primary_color' => '#111827',
                'secondary_color' => '#f59e0b',
                'font_family' => 'tajawal',
                'currency_code' => 'ILS',
                'currency_symbol' => '₪',
                'business_day_starts_at' => '00:00',
                'cashbox_float' => '-10',
            ])
            ->assertSessionHasErrors('cashbox_float_cents');

        $this->assertSame(self::DEFAULT_FLOAT_CENTS, app(RestaurantSettings::class)->cashbox_float_cents);
    }

    /**
     * The closing form says how much to leave behind, so emptying the drawer
     * takes no arithmetic.
     */
    public function test_the_cash_box_page_says_what_to_leave_in_the_drawer(): void
    {
        $this->floatOf(40000);

        $this->actingAs($this->owner())
            ->get(route('admin.accounting.cashbox'))
            ->assertInertia(fn ($page) => $page
                ->where('nextOpeningCents', 40000)
                ->where('summary.opening_cents', 40000));
    }
}
