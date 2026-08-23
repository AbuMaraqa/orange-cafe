<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Installing a new version over an old one has to finish the job.
 *
 * Migrations move the schema forward. Nothing moved the rows: reference
 * data was seeded once by app:first-run-setup, which only runs when there
 * is no administrator — so an installation created before a feature existed
 * never received the rows that feature needs, and reinstalling did not help
 * because reinstalling over real data is not a first run.
 *
 * The accounting module is the proof. Its screens are gated on permissions,
 * permissions are seeded rows, and no upgrade path delivered them — so a
 * complete, tested module answered 403 everywhere and hid itself from the
 * sidebar on every installation that had ever been made.
 *
 * app:sync-reference-data runs on every boot to close that gap. These tests
 * pin the two things that makes it safe: it delivers what is missing, and
 * it does nothing at all to what is already there.
 */
class UpgradeKeepsReferenceDataInStepTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_existing_install_receives_permissions_it_never_had(): void
    {
        // An installation from before the accounting module: roles exist,
        // permissions do not. Exactly the state found in production.
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('cashier', 'web');

        $this->assertSame(0, Permission::count(), 'Precondition: the old install has none.');

        $this->artisan('app:sync-reference-data')->assertSuccessful();

        $this->assertGreaterThan(0, Permission::count());
        $this->assertTrue(Role::findByName('admin', 'web')->hasPermissionTo('expenses.view'));
    }

    /**
     * An upgrade must not hand the till back the keys to the books.
     *
     * The money permissions are the owner's alone. A cashier holds none of
     * them — not the payroll, not a signed-off day, and not the cash box
     * screen — and since this command runs on every boot, a regression here
     * would quietly re-grant them on the next launch rather than at the
     * moment somebody changed the policy.
     *
     * Taking payment is untouched by any of this: it is guarded by role on
     * orders/{order}/pay, not by a permission in this list.
     */
    public function test_the_cashier_is_given_no_money_permissions(): void
    {
        $this->artisan('app:sync-reference-data');

        $cashier = Role::findByName('cashier', 'web');

        $this->assertSame(
            [],
            $cashier->permissions->pluck('name')->all(),
            'The accounting side of the business is the owner\'s.',
        );
    }

    public function test_accounting_categories_arrive_so_the_forms_are_usable(): void
    {
        $this->artisan('app:sync-reference-data');

        // Without these the expense form has nothing to pick and the
        // feature is unusable even once its permissions exist.
        $this->assertGreaterThan(0, DB::table('expense_categories')->count());
        $this->assertGreaterThan(0, DB::table('absence_categories')->count());
    }

    /**
     * The half that makes it safe to run on every boot: an up-to-date
     * database must come out unchanged, or an upgrade would quietly undo
     * whatever the cafe had configured.
     */
    public function test_running_it_twice_changes_nothing(): void
    {
        $this->artisan('app:sync-reference-data');

        $before = [
            'permissions' => Permission::count(),
            'roles' => Role::count(),
            'expense_categories' => DB::table('expense_categories')->count(),
            'absence_categories' => DB::table('absence_categories')->count(),
            'services' => DB::table('services')->count(),
        ];

        $this->artisan('app:sync-reference-data');

        foreach ($before as $table => $count) {
            $this->assertSame(
                $count,
                $table === 'permissions' ? Permission::count()
                    : ($table === 'roles' ? Role::count() : DB::table($table)->count()),
                "Re-running duplicated rows in {$table}."
            );
        }
    }

    /**
     * A cafe renames a category to suit itself. An upgrade must not put its
     * own wording back.
     */
    public function test_it_does_not_overwrite_what_the_cafe_changed(): void
    {
        $this->artisan('app:sync-reference-data');

        DB::table('expense_categories')->where('name', 'مشتريات')->update(['name' => 'مشتريات المطبخ']);

        $this->artisan('app:sync-reference-data');

        $this->assertDatabaseHas('expense_categories', ['name' => 'مشتريات المطبخ']);
    }
}
