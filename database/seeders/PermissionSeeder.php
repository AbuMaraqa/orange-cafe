<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates the money permissions and hands them to the roles that need
 * them.
 *
 * Safe to run again — every write is find-or-create, and roles are synced
 * rather than appended, so removing a permission from config/permissions.php
 * and re-seeding actually takes it away. That matters: a permission list
 * that only ever grows is one nobody can tighten.
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $names = config('permissions.available', []);

        foreach ($names as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (config('permissions.roles', []) as $roleName => $granted) {
            $role = Role::findOrCreate($roleName, 'web');

            $role->syncPermissions($granted === '*' ? $names : $granted);
        }

        // Spatie caches the permission map; without this, anything running
        // in the same process (the packaged app's first-run setup, the test
        // suite) keeps checking against the map from before this seeder.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
