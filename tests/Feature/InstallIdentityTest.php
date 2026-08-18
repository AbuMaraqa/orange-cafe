<?php

namespace Tests\Feature;

use App\Services\Native\InstallIdentity;
use App\Services\Native\NativeEnvSettings;
use Native\Laravel\Facades\Settings;
use RuntimeException;
use Tests\TestCase;

class InstallIdentityTest extends TestCase
{
    /** @var array<string, string> */
    private array $env = [];

    private function identity(): InstallIdentity
    {
        $settings = new class($this) extends NativeEnvSettings
        {
            public function __construct(private InstallIdentityTest $test) {}

            public function get(array $keys): array
            {
                return collect($keys)->mapWithKeys(fn ($k) => [$k => $this->test->envValue($k)])->all();
            }

            public function set(array $values): void
            {
                $this->test->writeEnv($values);
            }
        };

        return new InstallIdentity($settings);
    }

    public function envValue(string $key): ?string
    {
        return $this->env[$key] ?? null;
    }

    public function writeEnv(array $values): void
    {
        $this->env = array_merge($this->env, $values);
    }

    /**
     * Stands in for the Electron user-data store, which is the whole point of
     * the design: it outlives the .env that upgrades replace.
     */
    private function fakeSettingsStore(array $initial = []): object
    {
        $store = new class($initial)
        {
            public function __construct(public array $values) {}

            public function get(string $key, $default = null)
            {
                return $this->values[$key] ?? $default;
            }

            public function set(string $key, $value): void
            {
                $this->values[$key] = $value;
            }
        };

        Settings::swap($store);

        return $store;
    }

    public function test_a_fresh_install_replaces_both_shipped_secrets(): void
    {
        $store = $this->fakeSettingsStore();
        $this->env = ['APP_KEY' => 'base64:shipped', 'REVERB_APP_SECRET' => 'shipped-secret'];

        $changed = $this->identity()->ensure();

        $this->assertEqualsCanonicalizing(['APP_KEY', 'REVERB_APP_SECRET'], $changed);
        $this->assertNotSame('base64:shipped', $this->env['APP_KEY']);
        $this->assertNotSame('shipped-secret', $this->env['REVERB_APP_SECRET']);

        // 32 raw bytes, matching config('app.cipher') = AES-256-CBC.
        $this->assertSame(32, strlen(base64_decode(substr($this->env['APP_KEY'], 7))));

        // Remembered outside .env, so the next upgrade can restore them.
        $this->assertSame($this->env['APP_KEY'], $store->get('install_app_key'));
        $this->assertSame($this->env['REVERB_APP_SECRET'], $store->get('install_reverb_secret'));
    }

    public function test_a_second_boot_changes_nothing(): void
    {
        $this->fakeSettingsStore();
        $this->env = ['APP_KEY' => 'base64:shipped', 'REVERB_APP_SECRET' => 'shipped-secret'];

        $identity = $this->identity();
        $identity->ensure();
        $afterFirstBoot = $this->env;

        $this->assertSame([], $identity->ensure());
        $this->assertSame($afterFirstBoot, $this->env);
    }

    /**
     * The upgrade case: the installer overwrites .env with the build-time
     * placeholders, but this machine's identity must not change with it —
     * a new APP_KEY would invalidate anything encrypted under the old one.
     */
    public function test_an_upgrade_restores_the_secrets_this_machine_already_had(): void
    {
        $this->fakeSettingsStore([
            'install_app_key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'install_reverb_secret' => 'this-machines-secret',
        ]);
        $this->env = ['APP_KEY' => 'base64:shipped', 'REVERB_APP_SECRET' => 'shipped-secret'];

        $this->identity()->ensure();

        $this->assertSame('base64:'.base64_encode(str_repeat('k', 32)), $this->env['APP_KEY']);
        $this->assertSame('this-machines-secret', $this->env['REVERB_APP_SECRET']);
    }

    /**
     * Outside the native runtime (a plain `php artisan serve`, or a daemon
     * that isn't answering) the settings store throws. Booting the app
     * matters more than rotating a key, so this must not propagate.
     */
    public function test_it_survives_an_unreachable_settings_store(): void
    {
        Settings::swap(new class
        {
            public function get(string $key, $default = null)
            {
                throw new RuntimeException('daemon unreachable');
            }

            public function set(string $key, $value): void {}
        });

        $this->env = ['APP_KEY' => 'base64:shipped'];

        $this->assertSame([], $this->identity()->ensure());
        $this->assertSame('base64:shipped', $this->env['APP_KEY']);
    }
}
