<?php

use App\Http\Controllers\Api\MenuController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes
|--------------------------------------------------------------------------
|
| Deliberately not localized by URL — clients pass Accept-Language and get
| content back in that language (falling back to the default language),
| per the API localization requirement.
|
*/
Route::get('/menu', MenuController::class)->name('api.menu');
