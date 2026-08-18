<?php

namespace App\Services\Native;

/**
 * Lets the native control panel edit the handful of .env keys that differ
 * per installation (the LAN server port, the Reverb host/port, the public
 * URL that table QR codes are built from) without anyone having to open the
 * file by hand. Laravel has no built-in .env writer, so this does a minimal
 * line-based rewrite that preserves everything else in the file untouched.
 *
 * Changes only take effect after the native app restarts (see
 * NativeAppServiceProvider, which reads these at boot) — env() is never
 * safe to re-read mid-request once PHP has booted, and these values are
 * also used to spawn child processes, not just read in PHP.
 */
class NativeEnvSettings
{
    /**
     * Reads straight from the .env file rather than via env()/config().
     * NativePHP's packaged build caches config (bootstrap/cache/config.php),
     * and Laravel skips loading .env entirely once that cache exists — every
     * env() call outside a config file then returns null regardless of what
     * .env actually contains. That would silently blank out this settings
     * form (and make saving fail, since the blanked "required" fields no
     * longer validate), so this reads the file directly instead — correct
     * either way, cached config or not.
     *
     * @param  string[]  $keys
     * @return array<string, ?string>
     */
    public function get(array $keys): array
    {
        $lines = $this->lines();
        $values = [];

        foreach ($lines as $line) {
            foreach ($keys as $key) {
                if (isset($values[$key])) {
                    continue;
                }

                if (preg_match('/^'.preg_quote($key, '/').'=(.*)$/', $line, $matches)) {
                    $values[$key] = $this->parseValue($matches[1]);
                }
            }
        }

        return collect($keys)->mapWithKeys(fn (string $key) => [$key => $values[$key] ?? null])->all();
    }

    /**
     * @param  array<string, string|null>  $values
     */
    public function set(array $values): void
    {
        $lines = $this->lines();
        $remaining = $values;

        foreach ($lines as $index => $line) {
            foreach ($remaining as $key => $value) {
                if (preg_match('/^'.preg_quote($key, '/').'=/', $line)) {
                    $lines[$index] = $this->formatLine($key, $value);
                    unset($remaining[$key]);

                    break;
                }
            }
        }

        foreach ($remaining as $key => $value) {
            $lines[] = $this->formatLine($key, $value);
        }

        file_put_contents($this->path(), implode(PHP_EOL, $lines).PHP_EOL);
    }

    public function path(): string
    {
        return base_path('.env');
    }

    /**
     * @return string[]
     */
    private function lines(): array
    {
        $path = $this->path();

        return is_file($path) ? file($path, FILE_IGNORE_NEW_LINES) : [];
    }

    private function parseValue(string $raw): string
    {
        if (preg_match('/^"(.*)"$/', $raw, $matches)) {
            return str_replace('\\"', '"', $matches[1]);
        }

        return $raw;
    }

    private function formatLine(string $key, ?string $value): string
    {
        $value ??= '';
        $needsQuotes = $value === '' || preg_match('/[\s#"]/', $value);

        return $key.'='.($needsQuotes ? '"'.str_replace('"', '\\"', $value).'"' : $value);
    }
}
