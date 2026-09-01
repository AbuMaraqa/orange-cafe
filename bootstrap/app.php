<?php

use App\Http\Middleware\EnsureRequestIsLocal;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RecordLanReachability;
use App\Http\Middleware\SyncUserLocale;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRoutes;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule) {
        $schedule->command('app:check-printer-statuses')->everyMinute();
    })
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            // Records that something other than this machine reached the
            // app, which is the only honest answer to "can the tablets get
            // here" — the control panel's own probes go to 127.0.0.1 and
            // would report a firewalled server as healthy. See
            // App\Services\Native\LanReachability.
            RecordLanReachability::class,
        ]);

        $middleware->alias([
            'localize' => LaravelLocalizationRoutes::class,
            'localizationRedirect' => LaravelLocalizationRedirectFilter::class,
            'localeSessionRedirect' => LocaleSessionRedirect::class,
            'sync.locale' => SyncUserLocale::class,
            'role' => RoleMiddleware::class,
            // Spatie ships this but registers no alias of its own on Laravel
            // 11 — every alias here is explicit. The accounting routes are
            // guarded per permission rather than per role, because a cashier
            // has to be able to count the till at night and must not be able
            // to reopen a day that was already signed off.
            'permission' => PermissionMiddleware::class,
            'local-only' => EnsureRequestIsLocal::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // A refusal is not a fault.
        //
        // Spatie throws UnauthorizedException when somebody reaches a screen
        // their role does not open, and Laravel reports it at ERROR with a
        // full stack trace. But the guard doing its job is the system
        // working: a cashier following a bookmark to the cash box after the
        // money permissions were taken away is answered correctly with a
        // 403, and there is nothing for anyone to fix.
        //
        // Left reported, the log fills with entries that read like faults —
        // two of them arrived within a minute of tightening the cashier's
        // permissions — and the failures that do need attention sit among
        // them looking identical. A log where everything is an error is a
        // log nobody reads.
        //
        // The refusal itself is unaffected: this changes what is written
        // down, never what is allowed.
        $exceptions->dontReport([
            UnauthorizedException::class,
        ]);
    })->create();
