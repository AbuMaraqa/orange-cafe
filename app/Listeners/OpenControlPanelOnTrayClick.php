<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Log;
use Native\Laravel\Facades\Window;
use Throwable;

/**
 * Brings the control panel back when the tray icon is clicked.
 *
 * Without this there is no way back into a running app. Double-clicking the
 * desktop icon does nothing at all: NativePHP takes the single-instance lock
 * and quits the second copy immediately, and the handler meant to focus the
 * existing window checks `this.mainWindow` — a property declared as null in
 * electron-plugin/src/index.ts and never assigned anywhere in the file. So
 * the app runs, the tray icon sits there, and every attempt to open it is
 * silently discarded. The owner concludes the program is broken and
 * reinstalls it.
 *
 * The tray does emit a click event into Laravel, which is the one hook that
 * still works, so that is what this hangs off.
 */
class OpenControlPanelOnTrayClick
{
    public const WINDOW_ID = 'control-panel';

    public function handle(object $event): void
    {
        try {
            // show() on a window that is already open focuses it; on one
            // that was closed it brings it back. Either is what a click on
            // the tray icon should do.
            Window::show(self::WINDOW_ID);
        } catch (Throwable $e) {
            // The window may never have been created — a boot where the
            // control panel failed to open. Recreate it rather than leaving
            // the click doing nothing.
            $this->reopen($e);
        }
    }

    private function reopen(Throwable $original): void
    {
        try {
            $base = request()?->getSchemeAndHttpHost();

            if (! $base) {
                Log::warning('Tray click could not resolve the internal server URL.', [
                    'error' => $original->getMessage(),
                ]);

                return;
            }

            Window::open(self::WINDOW_ID)
                ->url($base.'/native/panel')
                ->title('Orange Cafe — حالة الخادم')
                ->width(1040)
                ->height(660)
                ->resizable(true)
                ->maximizable(true);
        } catch (Throwable $e) {
            Log::error('Could not reopen the control panel from the tray.', ['error' => $e->getMessage()]);
        }
    }
}
