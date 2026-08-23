<?php

namespace App\Console\Commands;

use Database\Seeders\AccountingCategorySeeder;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\ServiceSeeder;
use Illuminate\Console\Command;

/**
 * Brings an existing installation's reference data up to date with the
 * version of the app that is now running.
 *
 * Migrations carry schema forward on an upgrade; nothing carried the *rows*
 * the app needs to function. Reference data was seeded once, by
 * app:first-run-setup, which only runs when there is no administrator — so
 * an install created before a feature existed never received the rows that
 * feature depends on, and no amount of reinstalling helped, because
 * reinstalling over real data is not a first run.
 *
 * That is not hypothetical. The accounting screens are gated on
 * permissions, permissions are seeded rows, and PermissionSeeder was never
 * in the first-run list at all. Result: a fully built, fully tested
 * accounting module that returned 403 on every screen and hid itself from
 * the sidebar, on every installation that has ever existed — looking for
 * all the world like a feature that had not been written.
 *
 * So this runs on every boot instead, and everything it calls is
 * idempotent: find-or-create for rows, sync for the permission map. Running
 * it against an up-to-date database changes nothing and costs a few
 * queries. Running it after an upgrade is what makes the upgrade complete.
 *
 * What it deliberately does NOT touch: menus, products, prices, printers,
 * tables. Those belong to the cafe, not to the version.
 */
class SyncReferenceDataCommand extends Command
{
    protected $signature = 'app:sync-reference-data';

    protected $description = 'Bring reference data (languages, roles, permissions, services, accounting categories) in step with this version. Safe to re-run.';

    /**
     * Order matters once: PermissionSeeder assigns to roles, so the roles
     * have to exist. It creates any it is missing, but seeding them first
     * keeps that a fallback rather than the normal path.
     *
     * @var array<int, class-string>
     */
    private const SEEDERS = [
        LanguageSeeder::class,
        RoleSeeder::class,
        PermissionSeeder::class,
        ServiceSeeder::class,
        AccountingCategorySeeder::class,
    ];

    public function handle(): int
    {
        foreach (self::SEEDERS as $seeder) {
            // Each in its own try: one failing seeder must not stop the
            // rest, or a single bad row on one customer's machine takes the
            // whole upgrade with it.
            try {
                $this->call('db:seed', ['--class' => $seeder, '--force' => true]);
            } catch (\Throwable $e) {
                $this->error(class_basename($seeder).' failed: '.$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
