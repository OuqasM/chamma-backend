<?php

use App\Http\Controllers\Api\V1\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Api\V1\Admin\BrandController as AdminBrandController;
use App\Http\Controllers\Api\V1\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Api\V1\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Api\V1\Admin\SettingController as AdminSettingController;
use App\Http\Controllers\Api\V1\Admin\UploadController;
use App\Http\Controllers\Api\V1\Admin\VisitController as AdminVisitController;
use App\Http\Controllers\Api\V1\BrandController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\HomeController;
use App\Http\Controllers\Api\V1\NavigationController;
use App\Http\Controllers\Api\V1\OfferController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\SitemapController;
use App\Http\Controllers\Api\V1\StoreController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Storefront API
|--------------------------------------------------------------------------
|
| Every endpoint is addressable as /api/{locale}/...
|
| The locale is a real path prefix (fr, ar, en) rather than a route
| parameter: that keeps each route to a single path parameter, which is what
| the framework's controller argument resolution assumes.
|
| SetLocale still receives the locale as a route default, so it can read it
| with $request->route('locale').
|
*/

Route::get('/store', StoreController::class)->name('api.store');

// Locale independent, because it covers every locale at once and each <loc>
// already carries its own path. The storefront's robots.txt points here.
Route::get('/sitemap.xml', SitemapController::class)->name('api.sitemap');

foreach (array_keys(config('chamma.locales')) as $locale) {
    Route::prefix($locale)
        ->name("api.{$locale}.")
        // `track` counts a page view against a visitor. On the GET routes only,
        // so a cart submission is not also recorded as a fresh visit, and an
        // admin's own requests never land in the visitor list.
        ->middleware("locale:{$locale}")
        ->group(function () {
            // Locale scoped: the shell needs translated menu labels on first
            // paint, so it is not worth a second request.
            Route::get('/navigation', NavigationController::class)->middleware('track')
                ->name('navigation');

            Route::get('/home', HomeController::class)->middleware('track')->name('home');
            Route::get('/offers', OfferController::class)->middleware('track')->name('offers');

            Route::get('/brands', [BrandController::class, 'index'])->middleware('track')->name('brands.index');
            Route::get('/brands/{slug}', [BrandController::class, 'show'])->middleware('track')->name('brands.show');

            Route::get('/categories', [CategoryController::class, 'index'])->middleware('track')->name('categories.index');
            Route::get('/categories/{slug}', [CategoryController::class, 'show'])->middleware('track')->name('categories.show');

            Route::get('/products', [ProductController::class, 'index'])->middleware('track')->name('products.index');
            Route::get('/products/{slug}', [ProductController::class, 'show'])->middleware('track')->name('products.show');
            Route::get('/search', [ProductController::class, 'search'])->middleware('track')->name('search');

            // An order lookup is a page view like any other, but a failed guess
            // is not a visit worth counting, so it is left untracked.
            Route::get('/orders/{reference}', [CheckoutController::class, 'show'])->name('orders.show');
            Route::get('/checkout/options', [CheckoutController::class, 'options'])->name('checkout.options');
            Route::post('/checkout', [CheckoutController::class, 'store'])->name('checkout');
            Route::post('/checkout/quote', [CheckoutController::class, 'quote'])->name('checkout.quote');

            // Login is locale prefixed so its error message comes back
            // translated, exactly like the storefront.
            Route::post('/admin/login', [AdminAuthController::class, 'login'])->name('admin.login');
        });
}

/*
|--------------------------------------------------------------------------
| Admin API (Sanctum token auth)
|--------------------------------------------------------------------------
|
| Locale independent: the admin panel sends ?locale=ar (or an Accept-Language
| header) and SetLocale resolves it, so one authenticated surface serves every
| language without duplicating routes.
|
*/

Route::prefix('admin')->name('api.admin.')->group(function () {
    Route::middleware(['auth:sanctum', 'admin'])->group(function () {
        Route::get('/me', [AdminAuthController::class, 'me'])->name('me');
        Route::post('/logout', [AdminAuthController::class, 'logout'])->name('logout');

        Route::get('/dashboard', DashboardController::class)->name('dashboard');

        Route::post('/uploads', UploadController::class)->name('uploads');
        Route::post('/uploads/artwork', [UploadController::class, 'artwork'])->name('uploads.artwork');

        Route::get('/products', [AdminProductController::class, 'index'])->name('products.index');
        Route::post('/products', [AdminProductController::class, 'store'])->name('products.store');
        Route::get('/products/{product}', [AdminProductController::class, 'show'])->name('products.show');
        Route::put('/products/{product}', [AdminProductController::class, 'update'])->name('products.update');
        Route::patch('/products/{product}/toggle', [AdminProductController::class, 'toggle'])->name('products.toggle');
        Route::patch('/products/{product}/availability', [AdminProductController::class, 'toggleAvailability'])->name('products.availability');
        Route::delete('/products/{product}', [AdminProductController::class, 'destroy'])->name('products.destroy');
        Route::post('/products/{product}/restore', [AdminProductController::class, 'restore'])->name('products.restore');

        Route::get('/brands', [AdminBrandController::class, 'index'])->name('brands.index');
        Route::post('/brands', [AdminBrandController::class, 'store'])->name('brands.store');
        Route::put('/brands/{brand}', [AdminBrandController::class, 'update'])->name('brands.update');
        Route::delete('/brands/{brand}', [AdminBrandController::class, 'destroy'])->name('brands.destroy');

        Route::get('/categories', [AdminCategoryController::class, 'index'])->name('categories.index');
        Route::post('/categories', [AdminCategoryController::class, 'store'])->name('categories.store');
        Route::put('/categories/{category}', [AdminCategoryController::class, 'update'])->name('categories.update');
        Route::delete('/categories/{category}', [AdminCategoryController::class, 'destroy'])->name('categories.destroy');

        Route::get('/orders', [AdminOrderController::class, 'index'])->name('orders.index');
        Route::get('/orders/{order}', [AdminOrderController::class, 'show'])->name('orders.show');
        Route::patch('/orders/{order}/status', [AdminOrderController::class, 'updateStatus'])->name('orders.status');

        // Read-only. The tracker writes these rows; nothing in the panel edits
        // or deletes them, because a visitor record is data about a person
        // rather than content to be managed.
        Route::get('/visits', [AdminVisitController::class, 'index'])->name('visits.index');

        // Where order alerts go. Owned by the admin panel rather than .env
        // because who is on duty changes without a deploy.
        Route::get('/settings', [AdminSettingController::class, 'show'])->name('settings.show');
        Route::put('/settings', [AdminSettingController::class, 'update'])->name('settings.update');
    });
});
