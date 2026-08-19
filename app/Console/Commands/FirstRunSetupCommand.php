<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Settings\RestaurantSettings;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\LegacyOrangeDataSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\ServiceSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * One-shot bootstrap for a freshly packaged install (the standalone
 * Windows distributable's launcher runs this on first launch) — migrates,
 * seeds only the cafe-agnostic reference data (languages, roles,
 * waiter-call service types), creates a single admin account with a
 * generated password, and neutralizes the restaurant name. Deliberately
 * skips CatalogSeeder/ImportedDishesSeeder/OrangeMenuSeeder/PrinterSeeder/
 * RestaurantTableSeeder — those are orange-cafe's own demo content, not
 * something a purchasing cafe should be shipped.
 *
 * Safe to re-run: every seeder call underneath is itself idempotent
 * (updateOrCreate/firstOrCreate), and admin creation is skipped once an
 * admin already exists.
 */
class FirstRunSetupCommand extends Command
{
    protected $signature = 'app:first-run-setup
        {--restaurant-name=My Restaurant}
        {--with-legacy-data : Also load the real Orange Cafe catalog and staff}';

    protected $description = 'Bootstrap a freshly installed instance: migrate, seed reference data, create the first admin account, and link storage.';

    public function handle(): int
    {
        $this->call('migrate', ['--force' => true]);

        $this->call('db:seed', ['--class' => LanguageSeeder::class, '--force' => true]);
        $this->call('db:seed', ['--class' => RoleSeeder::class, '--force' => true]);
        $this->call('db:seed', ['--class' => ServiceSeeder::class, '--force' => true]);

        $this->seedLegacyDataIfRequested();

        $settings = app(RestaurantSettings::class);
        $settings->name = (string) $this->option('restaurant-name');
        $settings->save();

        $this->ensureAdminAccount();
        $this->linkStorage();

        $this->info('First-run setup complete.');

        return self::SUCCESS;
    }

    /**
     * Loads the cafe's own catalog and staff, when this build was made for
     * Orange Cafe rather than for a customer buying the product.
     *
     * Gated rather than unconditional, and defaulting to off: the JSON behind
     * LegacyOrangeDataSeeder is one specific cafe's menu, prices and staff
     * accounts. Shipping it to every install would mean the next customer
     * opens the app to somebody else's menu — and to four staff logins whose
     * password hashes they did not create.
     *
     * Set FIRST_RUN_LOAD_LEGACY_DATA=true in the .env used for the build (see
     * .env.production.example), or pass --with-legacy-data by hand. The value
     * is read through config('nativephp.load_legacy_data') rather than env():
     * the packaged app caches its config, and a cached config means env()
     * returns null everywhere outside a config file.
     */
    private function seedLegacyDataIfRequested(): void
    {
        $requested = $this->option('with-legacy-data')
            || filter_var(config('nativephp.load_legacy_data', false), FILTER_VALIDATE_BOOLEAN);

        if (! $requested) {
            return;
        }

        // Only ever on an empty catalog. Re-running the whole setup on a
        // live install must not quietly resurrect products the cafe deleted
        // — insertOrIgnore keeps existing rows intact, but a deleted row is
        // not an existing row.
        if (DB::table('products')->exists()) {
            $this->line('Products already present — skipping the legacy import.');

            return;
        }

        $this->call('db:seed', ['--class' => LegacyOrangeDataSeeder::class, '--force' => true]);
    }

    protected function ensureAdminAccount(): void
    {
        if (User::role('admin')->exists()) {
            $this->line('Admin account already exists — skipping.');

            return;
        }

        $email = 'admin@localhost';
        $password = Str::password(14, symbols: false);

        $admin = User::create([
            'name' => 'Administrator',
            'email' => $email,
            'password' => $password,
            'locale' => 'en',
            'email_verified_at' => now(),
        ]);
        $admin->assignRole('admin');

        // storage_path, not base_path: on the packaged desktop app the
        // program directory is not reliably writable, while NativePHP points
        // storage_path at a per-user application-data folder that always is.
        // The native control panel reads this exact file back and shows the
        // credentials on screen — without that, a customer's first launch
        // creates an admin account whose password nobody can find.
        $credentialsPath = storage_path('first-run-credentials.txt');
        file_put_contents($credentialsPath, implode(PHP_EOL, [
            'Admin account (generated on first run)',
            'Email:    '.$email,
            'Password: '.$password,
            '',
            'Change this password after logging in, then delete this file.',
        ]));

        $this->info("Admin account created — see {$credentialsPath}");
        $this->line("  Email:    {$email}");
        $this->line("  Password: {$password}");
    }

    /**
     * A convenience for web deployments only. The packaged desktop app does
     * not need it and must not attempt it: NativePHP relocates the storage
     * directory to a per-user application-data folder, while public/ lives
     * read-only inside the installed program directory — so the link would
     * either fail on permissions or point somewhere wrong. Uploaded images
     * are served by StorageFileController there instead, which needs no
     * symlink at all.
     */
    protected function linkStorage(): void
    {
        if (config('nativephp-internal.running')) {
            $this->line('Running under NativePHP — skipping storage:link (files are served by StorageFileController).');

            return;
        }

        if (file_exists(public_path('storage'))) {
            return;
        }

        try {
            $this->call('storage:link');
        } catch (Throwable $e) {
            // Symlinks can fail without elevated privileges on Windows —
            // a directory junction achieves the same result locally.
            if (PHP_OS_FAMILY === 'Windows') {
                exec('mklink /J "'.public_path('storage').'" "'.storage_path('app/public').'"');
            } else {
                throw $e;
            }
        }
    }
}
