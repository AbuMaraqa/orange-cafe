<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A night is not a date.
 *
 * This café closes at three in the morning, so its trading night lands on
 * two calendar dates and a day-granular report cuts it in half: the last
 * three hours are filed under a second, nearly empty day, and neither half
 * matches the drawer that was counted once. The range therefore carries a
 * time of day, and these are the figures that have to follow it.
 *
 * Asserted through the HTTP layer rather than against the private builders
 * because the query string is the part that has to survive — the report is
 * reached by a link, a bookmark, and an export URL built from the same
 * filters.
 */
class ReportRangeWithTimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(LanguageSeeder::class);
        $this->seed(PermissionSeeder::class);
    }

    private function admin(): User
    {
        $user = User::create([
            'name' => 'المالك',
            'email' => 'owner'.fake()->unique()->numberBetween(1, 99999).'@test.local',
            'password' => 'password',
            'locale' => 'ar',
            'email_verified_at' => now(),
        ]);
        $user->assignRole('admin');

        return $user;
    }

    private function paidAt(Carbon $moment, int $totalCents): Order
    {
        return Order::create([
            'reference' => 'R-'.fake()->unique()->numberBetween(1, 999999),
            'status' => 'completed',
            'total_cents' => $totalCents,
            'amount_paid_cents' => $totalCents,
            'paid_at' => $moment,
        ]);
    }

    private function report(User $admin, array $params): array
    {
        $response = $this->actingAs($admin)
            ->get(route('admin.accounting.reports', $params + ['tab' => 'orders']))
            ->assertOk();

        return $response->viewData('page')['props']['orders']['totals'];
    }

    public function test_a_night_that_ends_at_three_is_one_range(): void
    {
        $admin = $this->admin();

        // Yesterday's shift: opens at ten, still serving after midnight.
        $evening = Carbon::yesterday()->setTime(22, 0);
        $afterMidnight = Carbon::today()->setTime(2, 30);
        // And today's shift, which belongs to a different night entirely.
        $nextEvening = Carbon::today()->setTime(19, 0);

        $this->paidAt($evening, 5000);
        $this->paidAt($afterMidnight, 3000);
        $this->paidAt($nextEvening, 9900);

        $totals = $this->report($admin, [
            'from' => $evening->toDateString(),
            'to' => $afterMidnight->toDateString(),
            'from_time' => '10:00',
            'to_time' => '03:00',
        ]);

        $this->assertSame(2, $totals['orders_count'], 'the night is the two payments inside it');
        $this->assertSame(8000, $totals['collected_cents']);
    }

    public function test_without_a_time_the_range_is_still_whole_days(): void
    {
        $admin = $this->admin();

        $this->paidAt(Carbon::today()->setTime(0, 5), 1000);
        $this->paidAt(Carbon::today()->setTime(23, 55), 2000);

        $totals = $this->report($admin, [
            'from' => Carbon::today()->toDateString(),
            'to' => Carbon::today()->toDateString(),
        ]);

        $this->assertSame(2, $totals['orders_count'], 'an untouched time filter must not narrow the day');
        $this->assertSame(3000, $totals['collected_cents']);
    }

    public function test_the_boundary_minutes_belong_to_the_range(): void
    {
        $admin = $this->admin();

        $this->paidAt(Carbon::today()->setTime(10, 0), 1100);
        $this->paidAt(Carbon::today()->setTime(15, 0, 59), 2200);
        $this->paidAt(Carbon::today()->setTime(15, 1), 4400);

        $totals = $this->report($admin, [
            'from' => Carbon::today()->toDateString(),
            'to' => Carbon::today()->toDateString(),
            'from_time' => '10:00',
            'to_time' => '15:00',
        ]);

        $this->assertSame(2, $totals['orders_count'], 'both ends of the window are inside it, to the minute');
        $this->assertSame(3300, $totals['collected_cents']);
    }

    /**
     * A hand-edited URL is not a 500 on a money screen.
     */
    public function test_an_unreadable_time_falls_back_to_the_whole_day(): void
    {
        $admin = $this->admin();

        $this->paidAt(Carbon::today()->setTime(1, 0), 700);

        $totals = $this->report($admin, [
            'from' => Carbon::today()->toDateString(),
            'to' => Carbon::today()->toDateString(),
            'from_time' => 'nonsense',
            'to_time' => '99:99',
        ]);

        $this->assertSame(1, $totals['orders_count']);
    }

    /**
     * Typed backwards, swapped — the moments, not just the dates, so a
     * single-date range with its times the wrong way round is caught too.
     */
    public function test_a_range_typed_backwards_is_swapped(): void
    {
        $admin = $this->admin();

        $this->paidAt(Carbon::today()->setTime(12, 0), 6000);

        $totals = $this->report($admin, [
            'from' => Carbon::today()->toDateString(),
            'to' => Carbon::today()->toDateString(),
            'from_time' => '18:00',
            'to_time' => '09:00',
        ]);

        $this->assertSame(1, $totals['orders_count']);
        $this->assertSame(6000, $totals['collected_cents']);
    }
}
