<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\RestaurantTable;
use App\Models\User;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Which tables the room actually runs on.
 *
 * A sitting is a settled bill at a table — later rounds fold into the ticket
 * the table is already running — so the ranking counts bills, and it counts
 * them by exactly the rule the orders report does: paid in the range, not
 * voided. Tables nobody sat at are part of the answer, and bills with no
 * table are not.
 */
class TablesReportTest extends TestCase
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

    private function table(string $label, bool $active = true): RestaurantTable
    {
        return RestaurantTable::create(['label' => $label, 'is_active' => $active]);
    }

    private function sitting(?RestaurantTable $table, int $cents, string $paidAt = '2026-09-10 13:00:00', array $attributes = []): Order
    {
        return Order::create([
            'table_id' => $table?->id,
            'status' => 'served',
            'total_cents' => $cents,
            'amount_paid_cents' => $cents,
            'paid_at' => $paidAt,
            ...$attributes,
        ]);
    }

    /** @return array<string, mixed> */
    private function report(string $from = '2026-09-01', string $to = '2026-09-30'): array
    {
        $response = $this->actingAs($this->admin)->get(route('admin.accounting.reports', [
            'tab' => 'top', 'from' => $from, 'to' => $to,
        ]));

        $response->assertOk();

        $this->assertSame('top', $response->viewData('page')['props']['filters']['tab'], 'the tab is one the screen knows');

        return $response->viewData('page')['props']['tables'];
    }

    public function test_tables_are_ranked_by_how_many_times_they_were_sat_at(): void
    {
        $window = $this->table('شباك');
        $corner = $this->table('زاوية');

        $this->sitting($window, 2000, attributes: ['guest_count' => 2]);
        $this->sitting($window, 3000, attributes: ['guest_count' => 3]);
        $this->sitting($window, 1000);
        // Fewer sittings and more money: still second. "Most used" is how
        // often, and the money is beside it rather than deciding it.
        $this->sitting($corner, 9000, attributes: ['guest_count' => 6]);

        $report = $this->report();

        $this->assertSame(['شباك', 'زاوية'], array_column(array_slice($report['rows'], 0, 2), 'label'));

        $first = $report['rows'][0];
        $this->assertSame(3, $first['visits']);
        $this->assertSame(5, $first['guests'], 'the head counts that were taken');
        $this->assertSame(6000, $first['collected_cents']);
        $this->assertSame(2000, $first['average_cents']);
        $this->assertSame(75.0, $first['share']);

        $this->assertSame(4, $report['totals']['visits']);
        $this->assertSame(2, $report['totals']['tables_used']);
    }

    public function test_a_table_nobody_recorded_guests_for_says_so_rather_than_zero(): void
    {
        $this->sitting($this->table('1'), 2000);

        $this->assertNull($this->report()['rows'][0]['guests']);
    }

    /**
     * Half of "which tables get used" is which ones do not.
     */
    public function test_tables_nobody_sat_at_are_listed_after_the_ranking(): void
    {
        $this->sitting($this->table('مستخدمة'), 2000);
        $this->table('فاضية');
        $this->table('مخزنة', active: false);

        $report = $this->report();

        $this->assertSame(['مستخدمة', 'فاضية'], array_column($report['rows'], 'label'), 'a table taken off the floor is not idle');
        $this->assertTrue($report['rows'][1]['is_idle']);
        $this->assertSame(0, $report['rows'][1]['visits']);
        $this->assertSame(1, $report['totals']['tables_idle']);
    }

    /**
     * Trade, but not a table. Ranked in, it would put "no table" at the top
     * of a list of tables on any café with a busy counter.
     */
    public function test_bills_without_a_table_are_counted_apart(): void
    {
        $this->sitting($this->table('1'), 2000);
        $this->sitting(null, 1500);
        $this->sitting(null, 500);

        $report = $this->report();

        $this->assertCount(1, $report['rows']);
        $this->assertSame(['visits' => 2, 'collected_cents' => 2000], $report['takeaway']);
        $this->assertSame(1, $report['totals']['visits']);
    }

    public function test_only_settled_bills_in_the_range_count(): void
    {
        $table = $this->table('1');

        $this->sitting($table, 2000);
        $this->sitting($table, 2000, '2026-08-31 23:00:00');
        $this->sitting($table, 2000, attributes: ['status' => 'cancelled']);
        // Still sitting there: not a finished use of the table yet.
        Order::create(['table_id' => $table->id, 'status' => 'served', 'total_cents' => 4000]);

        $this->assertSame(1, $this->report()['rows'][0]['visits']);
    }

    /**
     * One bill forgotten open all afternoon is real data, and an average
     * would let it say every party stays for hours.
     */
    public function test_the_typical_stay_is_not_dragged_out_by_one_forgotten_bill(): void
    {
        $table = $this->table('1');

        foreach ([30, 40, 50, 360] as $minutes) {
            $order = $this->sitting($table, 1000, '2026-09-10 20:00:00');
            $order->forceFill(['created_at' => $order->paid_at->copy()->subMinutes($minutes)])->save();
        }

        $this->assertSame(45, $this->report()['rows'][0]['typical_minutes']);
    }

    public function test_the_ranking_exports_from_its_own_tab(): void
    {
        $this->sitting($this->table('شباك'), 2000);

        $response = $this->actingAs($this->admin)->get(route('admin.accounting.reports.export', [
            'tab' => 'top', 'from' => '2026-09-01', 'to' => '2026-09-30',
        ]));

        $response->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString('عدد الجلسات', $csv);
        $this->assertStringContainsString('شباك', $csv);
    }

    public function test_the_floor_cannot_read_it(): void
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
            ->get(route('admin.accounting.reports', ['tab' => 'top']))
            ->assertForbidden();
    }
}
