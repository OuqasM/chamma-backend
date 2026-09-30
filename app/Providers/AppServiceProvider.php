<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * The back-in-stock signup is the one unauthenticated endpoint on the
         * storefront that stores something a person handed us, and nothing
         * identifies the caller — the endpoint being reachable is the whole of
         * the authorisation. A bot that found it would otherwise be able to
         * fill the owner's calling list, and every number it wrote would be
         * dialled by a person.
         *
         * Keyed by IP and set well above the handful of attempts a real
         * customer makes, well below the rate at which the list becomes
         * garbage. Five per minute covers a family behind one connection.
         */
        RateLimiter::for('waitlist', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });
    }
}
