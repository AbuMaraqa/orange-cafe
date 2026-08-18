<?php

use App\Http\Controllers\Admin\AccountingController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\FeedbackController as AdminFeedbackController;
use App\Http\Controllers\Admin\LanguageController;
use App\Http\Controllers\Admin\MenuBuilderController;
use App\Http\Controllers\Admin\MenuController;
use App\Http\Controllers\Admin\ModifierGroupController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Admin\PrinterController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\RestaurantSettingController;
use App\Http\Controllers\Admin\RestaurantTableController;
use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\Admin\TagController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\CashierController;
use App\Http\Controllers\CustomerMenuController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\KitchenController;
use App\Http\Controllers\NativeSettingsController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\StaffOrderController;
use App\Http\Controllers\StorageFileController;
use App\Http\Controllers\WaiterCallController;
use App\Http\Controllers\WaiterController;
use App\Http\Controllers\WaiterOrderController;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

/*
|--------------------------------------------------------------------------
| Unlocalized routes
|--------------------------------------------------------------------------
|
| Everything below the localized group lives under /{locale}/. These two
| groups deliberately do not: neither is a page a human navigates to in a
| chosen language, and both must resolve without the `languages` table being
| readable — the whole point of the control panel is to still answer when
| something underneath is broken.
|
*/

/*
 * Native control window — opened directly by NativeAppServiceProvider at
 * boot, before any staff account exists, so it deliberately sits outside the
 * `auth` group. `local-only` keeps it unreachable from other devices on the
 * LAN (see EnsureRequestIsLocal for why that matters here).
 *
 * SESSION_DRIVER=database means the default `web` group's StartSession
 * middleware reads the `sessions` table on every request — exactly the
 * scenario this panel exists to survive, since it is often the first thing
 * opened when the database isn't ready yet. Session/cookie/CSRF middleware
 * is stripped so the panel never depends on the database just to render its
 * own "database is down" status.
 */
Route::middleware('local-only')
    ->withoutMiddleware([
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        VerifyCsrfToken::class,
        HandleInertiaRequests::class,
    ])
    ->prefix('native')
    ->group(function () {
        Route::get('/panel', [NativeSettingsController::class, 'panel'])->name('native.panel');
        Route::get('/panel/status', [NativeSettingsController::class, 'status'])->name('native.panel.status');
        Route::post('/panel/update', [NativeSettingsController::class, 'update'])->name('native.panel.update');
        Route::post('/panel/restart', [NativeSettingsController::class, 'restart'])->name('native.panel.restart');
        Route::post('/panel/credentials/dismiss', [NativeSettingsController::class, 'dismissCredentials'])
            ->name('native.panel.credentials.dismiss');
    });

/*
 * Uploaded menu photos, logos and QR logos, served straight off the `public`
 * disk instead of through the public/storage symlink.
 *
 * The packaged app can't rely on that symlink: NativePHP rewrites the
 * storage path to a per-user application-data directory at boot
 * (NativeServiceProvider::rewriteStoragePath), so a link created at build
 * time — if the build even preserved it, which Windows archives generally
 * don't — points at the developer's machine. Where the symlink does exist
 * (Laragon, nginx) the web server answers the file before Laravel is
 * reached, so this route simply never runs there.
 */
Route::get('/storage/{path}', StorageFileController::class)
    ->where('path', '.*')
    ->name('storage.serve');

/*
|--------------------------------------------------------------------------
| Localized web routes
|--------------------------------------------------------------------------
|
| Every page (staff and customer-facing) lives under a /{locale}/ prefix —
| including the default language — so URLs are always unambiguous and
| shareable (e.g. /ar/dashboard, /en/menu). `LaravelLocalization::setLocale()`
| resolves that segment (or the browser/session/default) into the current
| request locale before any route below is matched.
|
*/
Route::group([
    'prefix' => LaravelLocalization::setLocale(),
    'middleware' => ['localize', 'localizationRedirect', 'localeSessionRedirect', 'sync.locale'],
], function () {

    // Root is the customer-facing entry point — scanning a table's QR
    // already lands on /menu directly with table_id in the query string,
    // but a bare / (e.g. someone typing the domain by hand) should still
    // reach the menu rather than the Breeze scaffold page.
    Route::get('/', fn () => redirect()->route('menu.index'))->name('home');

    // Staff-facing entry point: /admin sends an admin straight to their
    // dashboard, and sends anyone not logged in to /login first (the
    // `auth` middleware remembers /admin as the intended URL and returns
    // here after a successful login).
    Route::get('/admin', fn () => redirect()->route('dashboard'))
        ->middleware(['auth', 'role:admin'])
        ->name('admin.home');

    Route::get('/dashboard', DashboardController::class)->middleware(['auth', 'verified'])->name('dashboard');

    Route::middleware('auth')->group(function () {
        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    });

    Route::middleware(['auth', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
        Route::resource('users', UserController::class)->except(['show']);

        Route::resource('languages', LanguageController::class)->except(['show']);
        Route::patch('languages/{language}/toggle-active', [LanguageController::class, 'activate'])->name('languages.activate');
        Route::patch('languages/{language}/make-default', [LanguageController::class, 'makeDefault'])->name('languages.default');
        Route::patch('languages-reorder', [LanguageController::class, 'reorder'])->name('languages.reorder');

        Route::resource('menus', MenuController::class)->except(['show']);
        Route::get('menus/{menu}/builder', [MenuBuilderController::class, 'edit'])->name('menus.builder');
        Route::post('menus/{menu}/sections', [MenuBuilderController::class, 'storeSection'])->name('menus.sections.store');
        Route::patch('menus/{menu}/sections-reorder', [MenuBuilderController::class, 'reorderSections'])->name('menus.sections.reorder');
        Route::delete('menus/sections/{section}', [MenuBuilderController::class, 'destroySection'])->name('menus.sections.destroy');
        Route::post('menus/sections/{section}/items', [MenuBuilderController::class, 'storeItem'])->name('menus.items.store');
        Route::patch('menus/sections/{section}/items-reorder', [MenuBuilderController::class, 'reorderItems'])->name('menus.items.reorder');
        Route::patch('menus/items/{item}', [MenuBuilderController::class, 'updateItem'])->name('menus.items.update');
        Route::delete('menus/items/{item}', [MenuBuilderController::class, 'destroyItem'])->name('menus.items.destroy');

        Route::resource('categories', CategoryController::class)->except(['show']);
        Route::resource('products', ProductController::class)->except(['show']);
        Route::resource('modifier-groups', ModifierGroupController::class)->except(['show']);
        Route::resource('tags', TagController::class)->except(['show']);
        Route::resource('services', ServiceController::class)->except(['show']);
        Route::resource('printers', PrinterController::class)->except(['show']);
        Route::post('printers/{printer}/test', [PrinterController::class, 'test'])->name('printers.test');

        Route::get('settings', [RestaurantSettingController::class, 'edit'])->name('settings.edit');
        Route::post('settings', [RestaurantSettingController::class, 'update'])->name('settings.update');
        Route::get('settings/wifi-qr', [RestaurantSettingController::class, 'wifiQrCode'])->name('settings.wifi-qr');

        Route::get('tables', [RestaurantTableController::class, 'index'])->name('tables.index');
        Route::post('tables', [RestaurantTableController::class, 'store'])->name('tables.store');
        Route::patch('tables/{table}', [RestaurantTableController::class, 'update'])->name('tables.update');
        Route::patch('tables/{table}/position', [RestaurantTableController::class, 'updatePosition'])->name('tables.position');
        Route::delete('tables/{table}', [RestaurantTableController::class, 'destroy'])->name('tables.destroy');
        Route::get('tables/{table}/qr', [RestaurantTableController::class, 'qrCode'])->name('tables.qr');
        Route::get('tables-qr', [RestaurantTableController::class, 'qrCodesAll'])->name('tables.qr-all');
        Route::get('tables/{table}/services/{service}/qr', [RestaurantTableController::class, 'serviceQrCode'])->name('tables.service-qr');

        Route::get('orders', [AdminOrderController::class, 'index'])->name('orders.index');
        Route::get('accounting', [AccountingController::class, 'index'])->name('accounting.index');

        Route::get('feedback', [AdminFeedbackController::class, 'index'])->name('feedback.index');
        Route::patch('feedback/{feedback}/read', [AdminFeedbackController::class, 'markRead'])->name('feedback.read');
        Route::delete('feedback/{feedback}', [AdminFeedbackController::class, 'destroy'])->name('feedback.destroy');
    });

    Route::middleware(['auth', 'role:cashier|kitchen|admin'])
        ->patch('orders/{order}/status', [StaffOrderController::class, 'updateStatus'])
        ->name('orders.status');

    Route::middleware(['auth', 'role:waiter|admin'])
        ->post('orders/{order}/confirm', [StaffOrderController::class, 'confirm'])
        ->name('orders.confirm');

    Route::middleware(['auth', 'role:admin'])
        ->delete('orders/{order}/items/{item}', [StaffOrderController::class, 'removeItem'])
        ->name('orders.items.destroy');

    Route::middleware(['auth', 'role:cashier|admin'])
        ->patch('orders/{order}/pay', [StaffOrderController::class, 'markPaid'])
        ->name('orders.pay');

    Route::middleware(['auth', 'role:waiter|cashier|admin'])
        ->get('orders/{order}/invoice', [StaffOrderController::class, 'invoice'])
        ->name('orders.invoice');

    Route::middleware(['auth', 'role:waiter|cashier|admin'])
        ->patch('orders/{order}/table', [StaffOrderController::class, 'moveTable'])
        ->name('orders.move-table');

    Route::middleware(['auth', 'role:waiter|cashier|kitchen|admin'])
        ->post('orders/{order}/print', [StaffOrderController::class, 'print'])
        ->name('orders.print');

    Route::middleware(['auth', 'role:cashier|admin'])
        ->post('orders/{order}/print-cashier', [StaffOrderController::class, 'printForCashier'])
        ->name('orders.print-cashier');

    Route::middleware(['auth', 'role:waiter|admin'])->get('/waiter', WaiterController::class)->name('waiter.index');
    Route::middleware(['auth', 'role:waiter|admin'])->get('/waiter/new-order', [WaiterOrderController::class, 'create'])->name('waiter.orders.create');
    Route::middleware(['auth', 'role:waiter|admin'])->post('/waiter/orders', [OrderController::class, 'store'])->name('waiter.orders.store');
    Route::middleware(['auth', 'role:waiter|admin'])->post('/waiter/orders/{order}/items', [WaiterOrderController::class, 'addItems'])->name('waiter.orders.addItems');
    Route::middleware(['auth', 'role:cashier|admin'])->get('/cashier', CashierController::class)->name('cashier.index');
    Route::middleware(['auth', 'role:kitchen|admin'])->get('/kitchen', KitchenController::class)->name('kitchen.index');

    Route::get('/menu', [CustomerMenuController::class, 'index'])->name('menu.index');
    Route::post('/menu/orders', [OrderController::class, 'store'])->name('menu.orders.store');
    Route::get('/menu/orders/{order}', [OrderController::class, 'show'])->name('menu.orders.show');
    Route::post('/waiter-calls', [WaiterCallController::class, 'store'])->name('waiter-calls.store');
    Route::get('/call/{table}/{service}', [WaiterCallController::class, 'quick'])->name('waiter-calls.quick');
    Route::post('/feedback', [FeedbackController::class, 'store'])->name('feedback.store');
    Route::middleware(['auth', 'role:waiter|admin'])
        ->patch('/waiter-calls/{call}/acknowledge', [WaiterCallController::class, 'acknowledge'])
        ->name('waiter-calls.acknowledge');

    require __DIR__.'/auth.php';
});
