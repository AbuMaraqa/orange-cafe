<?php

use App\Http\Controllers\Admin\AccountingController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\LanguageController;
use App\Http\Controllers\Admin\MenuBuilderController;
use App\Http\Controllers\Admin\MenuController;
use App\Http\Controllers\Admin\ModifierGroupController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Admin\PrinterController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\RestaurantSettingController;
use App\Http\Controllers\Admin\RestaurantTableController;
use App\Http\Controllers\Admin\TagController;
use App\Http\Controllers\CashierController;
use App\Http\Controllers\CustomerMenuController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\KitchenController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\StaffOrderController;
use App\Http\Controllers\WaiterCallController;
use App\Http\Controllers\WaiterController;
use App\Http\Controllers\WaiterOrderController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

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

    Route::get('/', function () {
        return Inertia::render('Welcome', [
            'canLogin' => Route::has('login'),
            'canRegister' => Route::has('register'),
            'laravelVersion' => Application::VERSION,
            'phpVersion' => PHP_VERSION,
        ]);
    })->name('home');

    Route::get('/dashboard', DashboardController::class)->middleware(['auth', 'verified'])->name('dashboard');

    Route::middleware('auth')->group(function () {
        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    });

    Route::middleware(['auth', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
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
        Route::resource('printers', PrinterController::class)->except(['show']);
        Route::post('printers/{printer}/test', [PrinterController::class, 'test'])->name('printers.test');

        Route::get('settings', [RestaurantSettingController::class, 'edit'])->name('settings.edit');
        Route::post('settings', [RestaurantSettingController::class, 'update'])->name('settings.update');

        Route::get('tables', [RestaurantTableController::class, 'index'])->name('tables.index');
        Route::post('tables', [RestaurantTableController::class, 'store'])->name('tables.store');
        Route::patch('tables/{table}', [RestaurantTableController::class, 'update'])->name('tables.update');
        Route::patch('tables/{table}/position', [RestaurantTableController::class, 'updatePosition'])->name('tables.position');
        Route::delete('tables/{table}', [RestaurantTableController::class, 'destroy'])->name('tables.destroy');
        Route::get('tables/{table}/qr', [RestaurantTableController::class, 'qrCode'])->name('tables.qr');
        Route::get('tables-qr', [RestaurantTableController::class, 'qrCodesAll'])->name('tables.qr-all');

        Route::get('orders', [AdminOrderController::class, 'index'])->name('orders.index');
        Route::get('accounting', [AccountingController::class, 'index'])->name('accounting.index');
    });

    Route::middleware(['auth', 'role:waiter|cashier|kitchen|admin'])
        ->patch('orders/{order}/status', [StaffOrderController::class, 'updateStatus'])
        ->name('orders.status');

    Route::middleware(['auth', 'role:waiter|cashier|admin'])
        ->delete('orders/{order}/items/{item}', [StaffOrderController::class, 'removeItem'])
        ->name('orders.items.destroy');

    Route::middleware(['auth', 'role:cashier|admin'])
        ->patch('orders/{order}/pay', [StaffOrderController::class, 'markPaid'])
        ->name('orders.pay');

    Route::middleware(['auth', 'role:waiter|cashier|admin'])
        ->get('orders/{order}/invoice', [StaffOrderController::class, 'invoice'])
        ->name('orders.invoice');

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
    Route::middleware(['auth', 'role:waiter|admin'])
        ->patch('/waiter-calls/{call}/acknowledge', [WaiterCallController::class, 'acknowledge'])
        ->name('waiter-calls.acknowledge');

    require __DIR__.'/auth.php';
});
