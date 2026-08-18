<?php

namespace App\Services\Native;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Native\Laravel\Facades\Settings;
use Throwable;

/**
 * Gives each packaged installation secrets of its own, replacing the ones
 * baked into the installer at build time.
 *
 * One installer is handed to every customer, so the APP_KEY and
 * REVERB_APP_SECRET present at build time are identical on every machine and
 * readable by anyone who unpacks the .exe. APP_KEY signs sessions and
 * cookies; REVERB_APP_SECRET is what authorises subscribing to the private
 * `orders` channel and publishing to it. With the shipped values, someone on
 * the restaurant's Wi-Fi holding a copy of the installer could read the live
 * order feed or inject events onto the kitchen screen without ever logging in.
 *
 * The generated values are kept in NativePHP's settings store — the Electron
 * user-data directory, outside both the app directory and the database — so
 * they survive an upgrade (which ships a fresh .env) and a database reset.
 * Re-randomising APP_KEY on every upgrade instead would quietly invalidate
 * anything encrypted with the previous one.
 */
class InstallIdentity
{
    private const APP_KEY_SETTING = 'install_app_key';

    private const REVERB_SECRET_SETTING = 'install_reverb_secret';

    public function __construct(private NativeEnvSettings $env) {}

    /**
     * Writes this machine's secrets into .env, generating them the first
     * time. Must run before any child process is spawned: they read .env at
     * their own startup, so what this writes is what they will use.
     *
     * @return string[] the .env keys actually changed
     */
    public function ensure(): array
    {
        try {
            $wanted = [
                'APP_KEY' => Settings::get(self::APP_KEY_SETTING) ?: $this->generateAppKey(),
                'REVERB_APP_SECRET' => Settings::get(self::REVERB_SECRET_SETTING) ?: Str::random(40),
            ];

            Settings::set(self::APP_KEY_SETTING, $wanted['APP_KEY']);
            Settings::set(self::REVERB_SECRET_SETTING, $wanted['REVERB_APP_SECRET']);

            $current = $this->env->get(array_keys($wanted));

            $changed = array_filter(
                $wanted,
                fn (string $value, string $key) => ($current[$key] ?? null) !== $value,
                ARRAY_FILTER_USE_BOTH
            );

            if ($changed !== []) {
                $this->env->set($changed);
                Log::info('Wrote install-specific secrets to .env.', ['keys' => array_keys($changed)]);
            }

            return array_keys($changed);
        } catch (Throwable $e) {
            // Leaving the shipped defaults in place is degraded but working,
            // which is the right trade for a boot hook — the alternative is
            // an app that won't start at all.
            Log::error('Could not establish install-specific secrets.', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * 32 bytes, matching the AES-256-CBC cipher in config/app.php — the same
     * shape `php artisan key:generate` produces.
     */
    private function generateAppKey(): string
    {
        return 'base64:'.base64_encode(random_bytes(32));
    }
}
