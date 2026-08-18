<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * تشغيل تطبيق سطح المكتب في وضع التطوير.
 *
 * A thin wrapper around `native:serve` that exists for two reasons, both of
 * which cost real minutes every single time the app is started by hand:
 *
 *  1. `native:serve` re-runs `npm install` on EVERY launch — its
 *     DevelopCommand calls installNPMDependencies(force: true) unconditionally
 *     — and npm's own `postinstall` there is `electron-builder
 *     install-app-deps`. That is a couple of minutes of "Fetching latest
 *     dependencies…" before Electron is even asked to start, on a
 *     node_modules that was already complete and unchanged. Nothing in it is
 *     needed unless NativePHP itself was just updated, so this command passes
 *     --no-dependencies by default and offers --with-dependencies for the one
 *     run after a `composer update`.
 *
 *  2. VS Code sets ELECTRON_RUN_AS_NODE=1 for its extension host, and every
 *     terminal/task spawned from the editor inherits it. Electron honours that
 *     variable and starts as a plain Node process instead of as Electron — so
 *     `import { app } from 'electron'` resolves to the npm *downloader* shim
 *     (whose export is just a path string) rather than the built-in module,
 *     and the boot dies before any window appears with:
 *
 *         TypeError: Cannot read properties of undefined (reading 'exports')
 *             at cjsPreparseModuleExports (node:internal/modules/esm/translators)
 *
 *     which names neither Electron nor the variable and reads like a broken
 *     node_modules. The same command run from a plain PowerShell window works,
 *     which is what makes it so confusing to chase. Clearing the variable here
 *     means it does not matter where the command is launched from.
 */
class NativeDev extends Command
{
    protected $signature = 'orange:dev
        {--with-dependencies : Re-run `npm install` first — needed only after NativePHP itself was updated}
        {--no-queue : Passed through to native:serve}';

    protected $description = 'تشغيل تطبيق Orange Cafe لسطح المكتب في وضع التطوير (أسرع من native:serve)';

    public function handle(): int
    {
        // putenv() with no "=" removes the variable from the real process
        // environment, so the Electron process spawned further down inherits
        // an environment without it. Setting it to "0" would not help:
        // Electron only checks whether the variable is present.
        if (getenv('ELECTRON_RUN_AS_NODE') !== false) {
            putenv('ELECTRON_RUN_AS_NODE');
            unset($_ENV['ELECTRON_RUN_AS_NODE'], $_SERVER['ELECTRON_RUN_AS_NODE']);

            $this->line('  <fg=gray>ELECTRON_RUN_AS_NODE was set (VS Code does this) — cleared for this run.</>');
        }

        $withDependencies = (bool) $this->option('with-dependencies');

        if (! $withDependencies) {
            $this->line('  <fg=gray>Skipping `npm install` — pass --with-dependencies after updating NativePHP.</>');
        }

        return $this->call('native:serve', array_filter([
            '--no-dependencies' => ! $withDependencies,
            '--no-queue' => (bool) $this->option('no-queue'),
            '--no-interaction' => true,
        ]));
    }
}
