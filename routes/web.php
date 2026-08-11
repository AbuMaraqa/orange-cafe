<?php

use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\LanguageController;
use App\Http\Controllers\Admin\ModifierGroupController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Admin\PrinterController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\RestaurantSettingController;
use App\Http\Controllers\Admin\RestaurantTableController;
use App\Http\Controllers\Admin\TagController;
use App\Http\Controllers\CashierController;
use App\Http\Controllers\CustomerMenuController;
use App\Http\Controllers\KitchenController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\StaffOrderController;
use App\Http\Controllers\WaiterController;
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

    Route::get('/dashboard', function () {
        return Inertia::render('Dashboard');
    })->middleware(['auth', 'verified'])->name('dashboard');

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
    });

    Route::middleware(['auth', 'role:waiter|cashier|kitchen|admin'])
        ->patch('orders/{order}/status', [StaffOrderController::class, 'updateStatus'])
        ->name('orders.status');

    Route::middleware(['auth', 'role:cashier|admin'])
        ->patch('orders/{order}/pay', [StaffOrderController::class, 'markPaid'])
        ->name('orders.pay');

    Route::middleware(['auth', 'role:waiter|cashier|admin'])
        ->get('orders/{order}/invoice', [StaffOrderController::class, 'invoice'])
        ->name('orders.invoice');

    Route::middleware(['auth', 'role:waiter|admin'])->get('/waiter', WaiterController::class)->name('waiter.index');
    Route::middleware(['auth', 'role:cashier|admin'])->get('/cashier', CashierController::class)->name('cashier.index');
    Route::middleware(['auth', 'role:kitchen|admin'])->get('/kitchen', KitchenController::class)->name('kitchen.index');

    Route::get('/menu', [CustomerMenuController::class, 'index'])->name('menu.index');
    Route::post('/menu/orders', [OrderController::class, 'store'])->name('menu.orders.store');
    Route::get('/menu/orders/{order}', [OrderController::class, 'show'])->name('menu.orders.show');

    require __DIR__.'/auth.php';
});
