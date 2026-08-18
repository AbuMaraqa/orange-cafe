<?php

namespace App\Services\Printing;

/**
 * Locates the `pdftoppm` executable that EscPosImageRenderer shells out to
 * when rasterizing a ticket. Thermal printing is entirely dependent on it,
 * and it is the one moving part of that pipeline that is not a PHP class,
 * so it gets its own resolver rather than a bare config string.
 *
 * Resolution happens at runtime, never in config/services.php, because the
 * packaged app ships a cached config (bootstrap/cache/config.php) built on
 * the developer's machine — a base_path() baked in there points at a
 * directory that does not exist on the customer's machine.
 */
class PopplerBinary
{
    private ?string $resolved = null;

    private bool $attempted = false;

    /**
     * Absolute path (or bare command name) to run, or null when poppler
     * can't be found at all — callers surface that as a clear "thermal
     * printing unavailable" rather than a cryptic process failure.
     */
    public function path(): ?string
    {
        if ($this->attempted) {
            return $this->resolved;
        }

        $this->attempted = true;

        return $this->resolved = $this->locate();
    }

    public function available(): bool
    {
        return $this->path() !== null;
    }

    private function locate(): ?string
    {
        // An explicit override always wins — a host with poppler installed
        // system-wide, or a non-standard location.
        if ($configured = config('services.poppler.pdftoppm_path')) {
            if ($this->isUsable($configured)) {
                return $configured;
            }
        }

        // The copy fetched by build/fetch-poppler.ps1 and bundled into the
        // packaged app. base_path() is correct here (evaluated per-request,
        // not cached) and resolves to the app directory inside the .exe's
        // resources on a packaged install.
        $bundled = base_path('bin/poppler/pdftoppm'.(PHP_OS_FAMILY === 'Windows' ? '.exe' : ''));

        if (is_file($bundled)) {
            return $bundled;
        }

        // Last resort: whatever is on PATH (Linux hosts with
        // poppler-utils installed, or a dev box that has it).
        return $this->isUsable('pdftoppm') ? 'pdftoppm' : null;
    }

    /**
     * A configured value may be either an absolute path or a bare command
     * name resolved through PATH — both need checking, and neither should
     * ever throw out of here.
     */
    private function isUsable(string $candidate): bool
    {
        if (str_contains($candidate, DIRECTORY_SEPARATOR) || str_contains($candidate, '/')) {
            return is_file($candidate);
        }

        $locator = PHP_OS_FAMILY === 'Windows' ? 'where' : 'command -v';
        $output = @shell_exec($locator.' '.escapeshellarg($candidate).' 2>&1');

        return is_string($output) && trim($output) !== '' && ! str_contains(strtolower($output), 'not find');
    }
}
