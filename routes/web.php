<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
|
| The storefront is a separate Vite application; this API only answers JSON
| and serves generated media.
|
*/

Route::get('/', function () {
    return response()->json([
        'name' => config('chamma.name'),
        'tagline' => 'Premium perfumes and beauty essentials',
        'api' => url('/api/'.config('chamma.default_locale').'/home'),
        'health' => url('/up'),
    ]);
});
