<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The catalog, handed to the till.
 *
 * The cashier is the one who hears first that a dish is finished or a price
 * has moved, and is standing at a screen when they do — so menus, sections,
 * products, add-ons and the rest are theirs to change. Nothing else in the
 * admin area comes with it, and the floor and the kitchen get none of it.
 */
class CatalogForTheTillTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> every catalog screen's index route */
    private const SCREENS = [
        'admin.menus.index',
        'admin.categories.index',
        'admin.products.index',
        'admin.modifier-groups.index',
        'admin.tags.index',
        'admin.services.index',
        'admin.service-charges.index',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(LanguageSeeder::class);
        $this->seed(PermissionSeeder::class);
    }

    private function user(string $role): User
    {
        $user = User::create([
            'name' => $role,
            'email' => $role.fake()->unique()->numberBetween(1, 99999).'@test.local',
            'password' => 'password',
            'locale' => 'ar',
            'email_verified_at' => now(),
        ]);
        $user->assignRole($role);

        return $user;
    }

    /** @return array<string, mixed> */
    private function product(string $name, int $priceCents): array
    {
        return [
            'slug' => '',
            'price_cents' => $priceCents,
            'is_active' => true,
            'translations' => [
                'ar' => ['name' => $name, 'description' => ''],
                'en' => ['name' => '', 'description' => ''],
            ],
        ];
    }

    public function test_the_cashier_opens_every_catalog_screen(): void
    {
        $cashier = $this->user('cashier');

        foreach (self::SCREENS as $screen) {
            $this->actingAs($cashier)->get(route($screen))->assertOk();
        }
    }

    public function test_the_cashier_adds_a_product_and_changes_its_price(): void
    {
        $cashier = $this->user('cashier');

        $this->actingAs($cashier)
            ->post(route('admin.products.store'), $this->product('عصير رمان', 1200))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $product = Product::query()->latest('id')->firstOrFail();
        $this->assertSame(1200, $product->price_cents);

        $this->actingAs($cashier)->get(route('admin.products.edit', $product))->assertOk();

        $this->actingAs($cashier)
            ->patch(route('admin.products.update', $product), [...$this->product('عصير رمان', 1500), 'slug' => $product->slug])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1500, $product->fresh()->price_cents);
    }

    /**
     * The catalog, and only the catalog. Users, printers, settings and the
     * money screens stay the owner's.
     */
    public function test_the_rest_of_the_admin_area_stays_closed_to_the_cashier(): void
    {
        $cashier = $this->user('cashier');

        foreach (['admin.users.index', 'admin.printers.index', 'admin.settings.edit', 'admin.tables.index', 'admin.accounting.reports'] as $screen) {
            $this->actingAs($cashier)->get(route($screen))->assertForbidden();
        }
    }

    public function test_the_floor_and_the_kitchen_do_not_get_the_catalog(): void
    {
        foreach (['waiter', 'kitchen'] as $role) {
            $user = $this->user($role);

            $this->actingAs($user)->get(route('admin.products.index'))->assertForbidden();
            $this->actingAs($user)->post(route('admin.products.store'), $this->product('شاي', 400))->assertForbidden();
        }

        $this->assertSame(0, Product::query()->count());
    }

    public function test_the_owner_still_has_it(): void
    {
        $admin = $this->user('admin');

        foreach (self::SCREENS as $screen) {
            $this->actingAs($admin)->get(route($screen))->assertOk();
        }
    }

    /**
     * An install upgraded before its permissions were re-synced has no
     * catalog.manage row at all. The owner must not be locked out of their
     * own menu by that — the admin role opens the door on its own.
     */
    public function test_the_owner_is_not_locked_out_before_the_permissions_are_synced(): void
    {
        $admin = $this->user('admin');

        Permission::query()->where('name', 'catalog.manage')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($admin->fresh())->get(route('admin.products.index'))->assertOk();
    }

    /**
     * The sidebar shows the catalog by asking for the permission, so the
     * cashier's page has to carry it.
     */
    public function test_the_cashiers_screens_are_told_they_have_the_catalog(): void
    {
        $this->actingAs($this->user('cashier'))
            ->get(route('cashier.index'))
            ->assertInertia(fn ($page) => $page->where('auth.permissions', fn ($permissions) => collect($permissions)->contains('catalog.manage')));
    }
}
