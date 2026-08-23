<?php

use App\Http\Controllers\Admin\Accounting\AbsenceCategoryController;
use App\Http\Controllers\Admin\Accounting\AbsenceController;
use App\Http\Controllers\Admin\Accounting\CashboxController;
use App\Http\Controllers\Admin\Accounting\ExpenseCategoryController;
use App\Http\Controllers\Admin\Accounting\ExpenseController;
use App\Http\Controllers\Admin\Accounting\ReportController;
use App\Http\Controllers\Admin\Accounting\StaffAccountController;
use App\Http\Controllers\Admin\AccountingController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\FeedbackController as AdminFeedbackController;
use App\Http\Controllers\Admin\LanguageController;
use App\Http\Controllers\Admin\MenuBuilderController;
use App\Http\Controllers\Admin\MenuController;
use App\Http\Controllers\Admin\ModifierGroupController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Admin\PrinterController;
use App\Http\Controllers\Admin\PrintJobController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\RestaurantSettingController;
use App\Http\Controllers\Admin\RestaurantTableController;
use App\Http\Controllers\Admin\ServiceChargeController;
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
use App\Http\Controllers\WebManifestController;
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
        Route::post('/panel/copy', [NativeSettingsController::class, 'copy'])->name('native.panel.copy');
        Route::post('/panel/load-data', [NativeSettingsController::class, 'loadLegacyData'])->name('native.panel.load-data');
        Route::post('/panel/clear-queue', [NativeSettingsController::class, 'clearQueue'])->name('native.panel.clear-queue');
        Route::post('/panel/backup/export', [NativeSettingsController::class, 'exportBackup'])->name('native.panel.backup.export');
        Route::post('/panel/backup/import', [NativeSettingsController::class, 'importBackup'])->name('native.panel.backup.import');
        Route::post('/panel/shutdown', [NativeSettingsController::class, 'shutdown'])->name('native.panel.shutdown');

        // The same shutdown, reachable by navigation rather than by POST.
        //
        // The tray menu is how people actually close this app, and its item
        // used to be NativePHP's built-in Menu::quit() — which quits Electron
        // and nothing else. The LAN server, Reverb, the queue and the
        // scheduler are separate processes, and NativePHP's own source admits
        // it does not reliably kill them ("Sometimes does not kill the
        // subprocess of php server"). So closing from the tray left a
        // `php -S` holding port 8000 with the app apparently shut down, and
        // the next launch had to fight its own corpse for the port.
        //
        // A menu item can only navigate, so the same work needs a GET.
        Route::get('/panel/shutdown', [NativeSettingsController::class, 'shutdownPage'])->name('native.panel.shutdown-page');
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
 * The web app manifest, deliberately outside the locale group.
 *
 * A tablet fetches this on its own, without the page's session and without
 * a locale segment to follow, so a manifest that only existed at
 * /ar/manifest.webmanifest would 404 for the device that actually needs it.
 * It carries no private data — the restaurant's own name and colours, which
 * are on every page already.
 */
Route::get('/manifest.webmanifest', WebManifestController::class)->name('manifest');

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
        Route::patch('menus-reorder', [MenuController::class, 'reorder'])->name('menus.reorder');
        Route::get('menus/{menu}/builder', [MenuBuilderController::class, 'edit'])->name('menus.builder');
        Route::post('menus/{menu}/sections', [MenuBuilderController::class, 'storeSection'])->name('menus.sections.store');
        Route::patch('menus/{menu}/sections-reorder', [MenuBuilderController::class, 'reorderSections'])->name('menus.sections.reorder');
        Route::patch('menus/sections/{section}', [MenuBuilderController::class, 'updateSection'])->name('menus.sections.update');
        Route::delete('menus/sections/{section}', [MenuBuilderController::class, 'destroySection'])->name('menus.sections.destroy');
        Route::post('menus/sections/{section}/items', [MenuBuilderController::class, 'storeItems'])->name('menus.items.store');
        Route::patch('menus/sections/{section}/items-reorder', [MenuBuilderController::class, 'reorderItems'])->name('menus.items.reorder');
        Route::patch('menus/items/{item}', [MenuBuilderController::class, 'updateItem'])->name('menus.items.update');
        Route::delete('menus/items/{item}', [MenuBuilderController::class, 'destroyItem'])->name('menus.items.destroy');

        Route::resource('categories', CategoryController::class)->except(['show']);
        // Hyphenated rather than categories/reorder: the resource route above
        // owns categories/{category}, and "reorder" would be swallowed by it.
        Route::patch('categories-reorder', [CategoryController::class, 'reorder'])->name('categories.reorder');
        Route::resource('products', ProductController::class)->except(['show']);
        Route::patch('products/{product}/toggle-active', [ProductController::class, 'toggleActive'])->name('products.toggle-active');
        Route::patch('products-reorder', [ProductController::class, 'reorder'])->name('products.reorder');
        Route::resource('modifier-groups', ModifierGroupController::class)->except(['show']);
        Route::patch('modifier-groups-reorder', [ModifierGroupController::class, 'reorder'])->name('modifier-groups.reorder');
        Route::get('modifier-groups/{modifierGroup}/products', [ModifierGroupController::class, 'products'])->name('modifier-groups.products');
        Route::put('modifier-groups/{modifierGroup}/products', [ModifierGroupController::class, 'syncProducts'])->name('modifier-groups.products.sync');
        Route::resource('tags', TagController::class)->except(['show']);
        Route::resource('services', ServiceController::class)->except(['show']);
        // Money on a bill with nothing to cook behind it — a table held
        // past two hours, corkage. Nothing to do with 'services' above,
        // which is the customer's call bell.
        Route::resource('service-charges', ServiceChargeController::class)->except(['show']);
        // Declared before the resource: `printers/discover` would otherwise
        // be swallowed by `printers/{printer}` and arrive as a lookup for a
        // printer named "discover".
        Route::get('printers/discover', [PrinterController::class, 'discover'])->name('printers.discover');
        Route::resource('printers', PrinterController::class)->except(['show']);
        Route::post('printers/{printer}/test', [PrinterController::class, 'test'])->name('printers.test');
        Route::post('printers/{printer}/test-print', [PrinterController::class, 'testPrint'])->name('printers.test-print');

        // The unprinted list, and the only route that puts a held ticket on
        // paper. Manual by design: a printer coming back must not empty an
        // hour of tickets into the kitchen by itself.
        Route::get('print-jobs', [PrintJobController::class, 'index'])->name('print-jobs.index');
        Route::post('print-jobs/{printJob}/reprint', [PrintJobController::class, 'reprint'])->name('print-jobs.reprint');
        Route::post('print-jobs/{printJob}/cancel', [PrintJobController::class, 'cancel'])->name('print-jobs.cancel');
        Route::post('print-jobs/cancel-all', [PrintJobController::class, 'cancelAll'])->name('print-jobs.cancel-all');

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

    /*
     * Money.
     *
     * Guarded by permission rather than by the role: a cashier runs the
     * till and counts it at night, and must not be able to reopen a day
     * that was already signed off. Everything else in this group stays on
     * role:admin, so no existing screen changes hands — and this group sits
     * OUTSIDE it, because a cashier has no admin role and would otherwise be
     * turned away at the group door before their permissions were consulted.
     *
     * The 'permission' middleware is Spatie's, already registered by its
     * service provider; config/permissions.php is the list and
     * PermissionSeeder grants it.
     */
    Route::middleware('auth')->prefix('admin/accounting')->name('admin.accounting.')->group(function () {
        Route::middleware('permission:cashbox.view')->group(function () {
            Route::get('cashbox', [CashboxController::class, 'index'])->name('cashbox');
            Route::get('cashbox/closings', [CashboxController::class, 'closings'])->name('cashbox.closings');
        });

        Route::post('cashbox/movements', [CashboxController::class, 'storeMovement'])
            ->middleware('permission:cashbox.collect')->name('cashbox.movements.store');
        Route::delete('cashbox/movements/{cashMovement}', [CashboxController::class, 'destroyMovement'])
            ->middleware('permission:cashbox.collect')->name('cashbox.movements.destroy');
        Route::post('cashbox/close', [CashboxController::class, 'close'])
            ->middleware('permission:cashbox.close')->name('cashbox.close');
        Route::post('cashbox/reopen', [CashboxController::class, 'reopen'])
            ->middleware('permission:cashbox.reopen')->name('cashbox.reopen');

        Route::get('expenses', [ExpenseController::class, 'index'])
            ->middleware('permission:expenses.view')->name('expenses.index');
        Route::post('expenses', [ExpenseController::class, 'store'])
            ->middleware('permission:expenses.create')->name('expenses.store');
        Route::patch('expenses/{expense}', [ExpenseController::class, 'update'])
            ->middleware('permission:expenses.update')->name('expenses.update');
        Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy'])
            ->middleware('permission:expenses.delete')->name('expenses.destroy');

        Route::middleware('permission:expense_categories.manage')->group(function () {
            Route::get('expense-categories', [ExpenseCategoryController::class, 'index'])->name('expense-categories.index');
            Route::post('expense-categories', [ExpenseCategoryController::class, 'store'])->name('expense-categories.store');
            Route::patch('expense-categories/{expenseCategory}', [ExpenseCategoryController::class, 'update'])->name('expense-categories.update');
            Route::delete('expense-categories/{expenseCategory}', [ExpenseCategoryController::class, 'destroy'])->name('expense-categories.destroy');
        });

        Route::get('absences', [AbsenceController::class, 'index'])
            ->middleware('permission:absences.view')->name('absences.index');
        Route::post('absences', [AbsenceController::class, 'store'])
            ->middleware('permission:absences.create')->name('absences.store');
        Route::patch('absences/{absence}', [AbsenceController::class, 'update'])
            ->middleware('permission:absences.update')->name('absences.update');
        Route::delete('absences/{absence}', [AbsenceController::class, 'destroy'])
            ->middleware('permission:absences.delete')->name('absences.destroy');

        Route::middleware('permission:absence_categories.manage')->group(function () {
            Route::get('absence-categories', [AbsenceCategoryController::class, 'index'])->name('absence-categories.index');
            Route::post('absence-categories', [AbsenceCategoryController::class, 'store'])->name('absence-categories.store');
            Route::patch('absence-categories/{absenceCategory}', [AbsenceCategoryController::class, 'update'])->name('absence-categories.update');
            Route::delete('absence-categories/{absenceCategory}', [AbsenceCategoryController::class, 'destroy'])->name('absence-categories.destroy');
        });

        Route::middleware('permission:staff_accounts.view')->group(function () {
            Route::get('staff', [StaffAccountController::class, 'index'])->name('staff.index');
            Route::get('staff/{user}', [StaffAccountController::class, 'show'])->name('staff.show');
            Route::post('staff/{user}/entries', [StaffAccountController::class, 'store'])->name('staff.entries.store');
            Route::delete('staff/{user}/entries/{entry}', [StaffAccountController::class, 'destroy'])->name('staff.entries.destroy');
        });

        Route::middleware('permission:reports.view')->group(function () {
            Route::get('reports', [ReportController::class, 'index'])->name('reports');
            Route::get('reports/export', [ReportController::class, 'export'])->name('reports.export');
            Route::get('reports/pdf', [ReportController::class, 'pdf'])->name('reports.pdf');
        });
    });

    Route::middleware(['auth', 'role:cashier|kitchen|admin'])
        ->patch('orders/{order}/status', [StaffOrderController::class, 'updateStatus'])
        ->name('orders.status');

    Route::middleware(['auth', 'role:waiter|admin'])
        ->post('orders/{order}/confirm', [StaffOrderController::class, 'confirm'])
        ->name('orders.confirm');

    Route::middleware(['auth', 'role:admin'])
        ->patch('orders/{order}/items/{item}', [StaffOrderController::class, 'updateItem'])
        ->name('orders.items.update');

    Route::middleware(['auth', 'role:admin'])
        ->delete('orders/{order}/items/{item}', [StaffOrderController::class, 'removeItem'])
        ->name('orders.items.destroy');

    Route::middleware(['auth', 'role:cashier|admin'])
        ->patch('orders/{order}/discount', [StaffOrderController::class, 'applyDiscount'])
        ->name('orders.discount');

    // Charging for a long-held table is the till's decision, the same one
    // a discount is, so it is guarded the same way — and, like a discount,
    // it is allowed after the kitchen has the order.
    Route::middleware(['auth', 'role:cashier|admin'])
        ->post('orders/{order}/service-charges', [StaffOrderController::class, 'addServiceCharge'])
        ->name('orders.service-charges.store');

    Route::middleware(['auth', 'role:cashier|admin'])
        ->patch('orders/{order}/pay', [StaffOrderController::class, 'markPaid'])
        ->name('orders.pay');

    Route::middleware(['auth', 'role:waiter|cashier|admin'])
        ->get('orders/{order}/invoice', [StaffOrderController::class, 'invoice'])
        ->name('orders.invoice');

    Route::middleware(['auth', 'role:waiter|cashier|admin'])
        ->patch('orders/{order}/table', [StaffOrderController::class, 'moveTable'])
        ->name('orders.move-table');

    // Closing a table without charging for it. Same roles as moving one:
    // both are the floor correcting itself, and both are refused on a bill
    // that has already been paid.
    Route::middleware(['auth', 'role:waiter|cashier|admin'])
        ->patch('orders/{order}/cancel', [StaffOrderController::class, 'cancel'])
        ->name('orders.cancel');

    Route::middleware(['auth', 'role:waiter|cashier|kitchen|admin'])
        ->post('orders/{order}/print', [StaffOrderController::class, 'print'])
        ->name('orders.print');

    Route::middleware(['auth', 'role:cashier|admin'])
        ->post('orders/{order}/print-cashier', [StaffOrderController::class, 'printForCashier'])
        ->name('orders.print-cashier');

    Route::middleware(['auth', 'role:waiter|admin'])->get('/waiter', WaiterController::class)->name('waiter.index');

    // The order-taking screen, open to the till as well as the floor.
    //
    // The URLs and route names still say "waiter" because that is where it
    // was built and renaming them would break every link that points at
    // them; the screen itself was never waiter-specific. A cashier opening a
    // table — a walk-in paying at the counter, a customer who ordered before
    // sitting down — types the order in exactly the same way, and it lands
    // on the same floor plan. Roles come from config/orders.php so the
    // routes and OrderController cannot drift apart on who counts as staff.
    $staffOrderRoles = 'role:'.implode('|', config('orders.placed_by_roles'));

    Route::middleware(['auth', $staffOrderRoles])->get('/waiter/new-order', [WaiterOrderController::class, 'create'])->name('waiter.orders.create');
    Route::middleware(['auth', $staffOrderRoles])->post('/waiter/orders', [OrderController::class, 'store'])->name('waiter.orders.store');
    Route::middleware(['auth', $staffOrderRoles])->post('/waiter/orders/{order}/items', [WaiterOrderController::class, 'addItems'])->name('waiter.orders.addItems');
    // Polled by the till's print alert. Open to any floor role: a waiter
    // needs to know the kitchen never saw the order just as much as the
    // cashier does. It returns counts and printer names only — the errors
    // and attempt counts stay on the admin screen.
    Route::middleware(['auth', 'role:waiter|cashier|kitchen|admin'])
        ->get('/staff/print-alerts', [PrintJobController::class, 'alerts'])->name('staff.print-alerts');
    Route::middleware(['auth', 'role:cashier|admin'])->get('/cashier', CashierController::class)->name('cashier.index');
    Route::middleware(['auth', 'role:kitchen|admin'])->get('/kitchen', KitchenController::class)->name('kitchen.index');

    Route::get('/menu', [CustomerMenuController::class, 'index'])->name('menu.index');
    Route::post('/menu/orders', [OrderController::class, 'store'])->name('menu.orders.store');
    Route::get('/menu/orders/{order}', [OrderController::class, 'show'])->name('menu.orders.show');
    Route::post('/waiter-calls', [WaiterCallController::class, 'store'])->name('waiter-calls.store');
    Route::get('/call/{table}/{service}', [WaiterCallController::class, 'quick'])->name('waiter-calls.quick');
    Route::post('/feedback', [FeedbackController::class, 'store'])->name('feedback.store');
    // The cashier too: half of what a table asks for is the bill, which
    // is the till's own job, and the person at the till is usually the one
    // standing still. A screen that shows a request it cannot resolve just
    // moves the problem.
    Route::middleware(['auth', 'role:waiter|cashier|admin'])
        ->patch('/waiter-calls/{call}/acknowledge', [WaiterCallController::class, 'acknowledge'])
        ->name('waiter-calls.acknowledge');

    require __DIR__.'/auth.php';
});
