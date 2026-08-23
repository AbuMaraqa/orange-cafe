<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\Accounting\BusinessDay;
use App\Services\Accounting\Cashbox;
use App\Settings\RestaurantSettings;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The café that closes at three in the morning.
 *
 * Its night is one shift, one set of staff and one drawer counted once —
 * but two calendar dates, and by midnight arithmetic the last three hours
 * of takings are filed under a day that has not started yet. The owner
 * closing up at 3am then reads a day that is missing its own last hours and
 * a tomorrow that already has money in it, and neither can be checked
 * against the till roll.
 *
 * `business_day_starts_at` moves the line. These are the figures that have
 * to move with it — and, just as importantly, the ones that must not move
 * at all for a café that left the setting at midnight.
 */
class BusinessDayStartTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(LanguageSeeder::class);
        $this->seed(PermissionSeeder::class);
    }

    private function dayStartsAt(string $time): void
    {
        $settings = app(RestaurantSettings::class);
        $settings->business_day_starts_at = $time;
        $settings->save();

        // The settings object and anything holding it are singletons for
        // the life of a request; a test changes the setting mid-request.
        app()->forgetInstance(RestaurantSettings::class);
        app()->forgetInstance(BusinessDay::class);
        app()->forgetInstance(Cashbox::class);
    }

    private function paidAt(Carbon $moment, int $cents): Order
    {
        return Order::create([
            'reference' => 'R-'.fake()->unique()->numberBetween(1, 999999),
            'status' => 'completed',
            'total_cents' => $cents,
            'amount_paid_cents' => $cents,
            'paid_at' => $moment,
        ]);
    }

    public function test_a_sale_after_midnight_belongs_to_the_night_before(): void
    {
        $this->dayStartsAt('06:00');

        $businessDay = app(BusinessDay::class);

        $this->assertSame('2026-08-23', $businessDay->dateOf(Carbon::parse('2026-08-24 02:30')));
        $this->assertSame('2026-08-24', $businessDay->dateOf(Carbon::parse('2026-08-24 06:00')));
        $this->assertSame('2026-08-24', $businessDay->dateOf(Carbon::parse('2026-08-24 23:59')));
    }

    public function test_the_drawer_counts_the_whole_night_as_one_day(): void
    {
        $this->dayStartsAt('06:00');

        // One night's trade: evening, and after midnight.
        $this->paidAt(Carbon::parse('2026-08-23 21:00'), 5000);
        $this->paidAt(Carbon::parse('2026-08-24 02:00'), 3000);
        // The next night, which the same drawer must not swallow.
        $this->paidAt(Carbon::parse('2026-08-24 20:00'), 7000);

        $cashbox = app(Cashbox::class);

        $this->assertSame(8000, $cashbox->collectedCents('2026-08-23'), 'the night is one day');
        $this->assertSame(7000, $cashbox->collectedCents('2026-08-24'));
    }

    /**
     * Closing up at 3am, the day being closed is the one just worked.
     */
    public function test_closing_at_three_in_the_morning_closes_yesterday(): void
    {
        $this->dayStartsAt('06:00');

        Carbon::setTestNow(Carbon::parse('2026-08-24 03:00'));

        $this->assertSame('2026-08-23', app(Cashbox::class)->today());

        Carbon::setTestNow(Carbon::parse('2026-08-24 06:01'));

        $this->assertSame('2026-08-24', app(Cashbox::class)->today());

        Carbon::setTestNow();
    }

    /**
     * The report with no time typed into it covers the same hours the
     * drawer was counted for, or paper and till disagree by three hours.
     */
    public function test_a_report_day_is_the_same_day_the_drawer_counted(): void
    {
        $this->dayStartsAt('06:00');

        $admin = User::create([
            'name' => 'المالك',
            'email' => 'owner'.fake()->unique()->numberBetween(1, 99999).'@test.local',
            'password' => 'password',
            'locale' => 'ar',
            'email_verified_at' => now(),
        ]);
        $admin->assignRole('admin');

        $this->paidAt(Carbon::parse('2026-08-23 21:00'), 5000);
        $this->paidAt(Carbon::parse('2026-08-24 02:00'), 3000);
        $this->paidAt(Carbon::parse('2026-08-24 20:00'), 7000);

        $totals = $this->actingAs($admin)
            ->get(route('admin.accounting.reports', [
                'from' => '2026-08-23',
                'to' => '2026-08-23',
                'tab' => 'orders',
            ]))
            ->assertOk()
            ->viewData('page')['props']['orders']['totals'];

        $this->assertSame(2, $totals['orders_count']);
        $this->assertSame(8000, $totals['collected_cents']);
    }

    /**
     * The café that shuts before midnight must not notice any of this.
     */
    public function test_at_midnight_nothing_changes(): void
    {
        $this->dayStartsAt('00:00');

        $this->paidAt(Carbon::parse('2026-08-23 23:50'), 4000);
        $this->paidAt(Carbon::parse('2026-08-24 00:10'), 6000);

        $cashbox = app(Cashbox::class);

        $this->assertSame(4000, $cashbox->collectedCents('2026-08-23'));
        $this->assertSame(6000, $cashbox->collectedCents('2026-08-24'));
    }

    /**
     * A setting somebody has hand-edited to nonsense must not take a money
     * screen down; midnight is the safe reading of "no usable value".
     */
    public function test_an_unreadable_setting_falls_back_to_midnight(): void
    {
        $this->dayStartsAt('25:99');

        $this->assertTrue(app(BusinessDay::class)->isCalendarDay());
        $this->assertSame('00:00', app(BusinessDay::class)->startsAt());
    }
}
