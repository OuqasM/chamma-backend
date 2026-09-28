<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CatalogService;
use App\Services\PaymentService;
use App\Services\ShippingZoneService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

class StoreController extends Controller
{
    /**
     * Runtime configuration for the SPA: languages, currency, Moroccan cities,
     * delivery rules. Changing the store's country only means editing config.
     */
    public function __invoke(Request $request): \Illuminate\Http\JsonResponse
    {
        $locale = App::getLocale();

        return response()->json([
            'name' => config('chamma.name'),
            'locale' => $locale,
            'direction' => config('chamma.locales.'.$locale.'.dir'),
            'og_locale' => config('chamma.locales.'.$locale.'.og_locale'),
            'fallback_locale' => config('chamma.default_locale'),

            'locales' => collect(config('chamma.locales'))->map(fn ($meta, $code) => [
                'code' => $code,
                'label' => $meta['native'],
                'english' => $meta['name'],
                'flag' => $meta['flag'],
                'dir' => $meta['dir'],
            ])->values(),

            'currency' => config('chamma.currency'),
            'payment_methods' => app(PaymentService::class)->methods($locale),
            'cities' => app(ShippingZoneService::class)->options($locale),

            'shipping' => [
                'flat_cost' => (float) config('chamma.shipping.flat_cost'),
                'free_threshold' => (float) config('chamma.shipping.free_threshold'),
                'estimate' => config('chamma.shipping.estimate_days.'.$locale),
            ],

            'contact' => [
                'email' => config('chamma.store.email'),
                'phone' => config('chamma.store.phone'),
                'whatsapp' => config('chamma.store.whatsapp'),
                'instagram' => config('chamma.store.instagram'),
                'facebook' => config('chamma.store.facebook'),
                'tiktok' => config('chamma.store.tiktok'),
                'address' => config('chamma.store.address.'.$locale),
            ],

            'catalog' => config('chamma.catalog'),
        ]);
    }
}
