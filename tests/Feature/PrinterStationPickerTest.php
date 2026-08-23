<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Menu;
use App\Models\User;
use App\Services\CashierReceiptPrinterService;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A printer's station decides what it prints, and the app matches it as an
 * exact string: 'cashier' for invoices and the cash drawer, the category's
 * own station name for kitchen tickets.
 *
 * It used to be a free-text box, so "Cashier", "كاشير" or a stray space
 * produced a printer that looked configured and printed nothing at all —
 * no invoice, no drawer, no error anywhere.
 */
class PrinterStationPickerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RoleSeeder::class);

        $user = User::create([
            'name' => 'Admin',
            'email' => 'admin@test.local',
            'password' => 'password',
            'locale' => 'ar',
            'email_verified_at' => now(),
        ]);
        $user->assignRole('admin');

        return $user;
    }

    private function categoryWithStation(string $station): void
    {
        $this->seed(LanguageSeeder::class);

        $menu = Menu::create([
            'slug' => 'm-'.fake()->unique()->numberBetween(1, 99999),
            'publish_status' => 'published',
            'is_active' => true,
            'sort_order' => 0,
            'ar' => ['name' => 'قائمة'],
            'en' => ['name' => 'Menu'],
        ]);

        Category::create([
            'menu_id' => $menu->id,
            'slug' => 'c-'.fake()->unique()->numberBetween(1, 99999),
            'station' => $station,
            'is_active' => true,
            'sort_order' => 0,
            'ar' => ['name' => $station],
            'en' => ['name' => $station],
        ]);
    }

    private function stations()
    {
        $response = $this->actingAs($this->admin())->get(route('admin.printers.create'));
        $response->assertOk();

        return collect($response->viewData('page')['props']['stations']);
    }

    public function test_the_cashier_station_is_always_offered(): void
    {
        $stations = $this->stations();

        $this->assertSame(
            CashierReceiptPrinterService::STATION,
            $stations->first()['value'],
            'the till printer must be the first thing offered'
        );
    }

    public function test_kitchen_stations_come_from_the_categories(): void
    {
        $this->categoryWithStation('مشروبات');
        $this->categoryWithStation('ماكولات');

        $values = $this->stations()->pluck('value');

        $this->assertContains('مشروبات', $values->all());
        $this->assertContains('ماكولات', $values->all());
    }

    public function test_a_station_is_offered_once_however_many_categories_use_it(): void
    {
        $this->categoryWithStation('مشروبات');
        $this->categoryWithStation('مشروبات');

        $values = $this->stations()->pluck('value');

        $this->assertSame(1, $values->filter(fn ($v) => $v === 'مشروبات')->count());
    }

    /**
     * A category that already uses 'cashier' must not produce a duplicate
     * entry beside the built-in one.
     */
    public function test_the_cashier_station_is_never_duplicated(): void
    {
        $this->categoryWithStation(CashierReceiptPrinterService::STATION);

        $values = $this->stations()->pluck('value');

        $this->assertSame(1, $values->filter(fn ($v) => $v === 'cashier')->count());
    }
}
