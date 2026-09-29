<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Store identity
    |--------------------------------------------------------------------------
    */

    'name' => env('STORE_NAME', 'Chamma Store'),

    /*
    |--------------------------------------------------------------------------
    | Localisation
    |--------------------------------------------------------------------------
    |
    | Every supported locale is addressable through the API and the storefront
    | URL structure: /api/{locale}/... and /{locale}/...
    |
    */

    'locales' => [
        'fr' => [
            'name' => 'Français',
            'native' => 'Français',
            'flag' => '🇫🇷',
            'dir' => 'ltr',
            'og_locale' => 'fr_MA',
        ],
        'ar' => [
            'name' => 'Arabic',
            'native' => 'العربية',
            'flag' => '🇲🇦',
            'dir' => 'rtl',
            'og_locale' => 'ar_MA',
        ],
        'en' => [
            'name' => 'English',
            'native' => 'English',
            'flag' => '🇬🇧',
            'dir' => 'ltr',
            'og_locale' => 'en_US',
        ],
    ],

    'default_locale' => env('STORE_DEFAULT_LOCALE', 'fr'),

    'fallback_locale' => env('STORE_FALLBACK_LOCALE', 'en'),

    /*
    |--------------------------------------------------------------------------
    | Commerce
    |--------------------------------------------------------------------------
    */

    'currency' => [
        'code' => env('STORE_CURRENCY', 'MAD'),
        'symbol' => env('STORE_CURRENCY_SYMBOL', 'MAD'),
        'decimals' => 2,
        // 249.00 -> "249 MAD" (thousand separated, trailing ".00" trimmed).
        'trim_decimals' => true,
    ],

    'store' => [
        'email' => env('STORE_EMAIL', 'contact@chamaperfumes.ma'),
        'phone' => env('STORE_PHONE', '+212 5 22 00 00 00'),
        'whatsapp' => env('STORE_WHATSAPP', '212522000000'),
        'instagram' => env('STORE_INSTAGRAM', 'chamaperfumes'),
        'facebook' => env('STORE_FACEBOOK', 'chamaperfumes'),
        'tiktok' => env('STORE_TIKTOK', 'chamaperfumes'),
        'address' => [
            'fr' => '12, boulevard d’Anfa, Casablanca, Maroc',
            'ar' => '12، شارع أنفا، الدار البيضاء، المغرب',
            'en' => '12, Anfa Boulevard, Casablanca, Morocco',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Shipping (Morocco)
    |--------------------------------------------------------------------------
    */

    /*
    |--------------------------------------------------------------------------
    | Payment
    |--------------------------------------------------------------------------
    |
    | `bank_transfer` is settled out of band: the shopper sends the transfer
    | receipt on WhatsApp to `whatsapp`, quoting the order reference. Fill the
    | bank details in to have them shown on the order confirmation page; leave
    | them empty and the shopper is only asked for the receipt.
    |
    */

    'payment' => [
        'methods' => ['cod', 'bank_transfer'],
        'default' => 'cod',
        'whatsapp' => env('STORE_PAYMENT_WHATSAPP', ''),
        'bank' => [
            'holder' => env('STORE_BANK_HOLDER', ''),
            'bank' => env('STORE_BANK_NAME', ''),
            'iban' => env('STORE_BANK_IBAN', ''),
            'rib' => env('STORE_BANK_RIB', ''),
            // Slip/QR the shopper can scan straight from their banking app.
            'image' => env('STORE_BANK_IMAGE', ''),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Shipping (Morocco)
    |--------------------------------------------------------------------------
    |
    | Delivery cost comes from the carrier tariff table in
    | config/shipping_zones.php (see ShippingZoneService); `flat_cost` is only
    | the fallback for a city missing from that table.
    |
    */

    'shipping' => [
        'free_threshold' => (float) env('STORE_FREE_SHIPPING_THRESHOLD', 700),
        'flat_cost' => (float) env('STORE_FLAT_SHIPPING_COST', 35),
        'remote_surcharge' => (float) env('STORE_REMOTE_SHIPPING_SURCHARGE', 20),
        'estimate_days' => [
            'fr' => '2 à 4 jours ouvrables',
            'ar' => 'من 2 إلى 4 أيام عمل',
            'en' => '2 to 4 business days',
        ],
        'remote_cities' => ['oujda', 'driouch', 'tan-tan', 'tan tan', 'laayoune', 'dakhla', 'boujdour', 'tarfaya', 'es-semara'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Media
    |--------------------------------------------------------------------------
    |
    | Images live in the web server's document root (public/images) so they are
    | served directly by the API container without a storage symlink.
    |
    */

    'disk' => 'storefront',

    /*
    |--------------------------------------------------------------------------
    | Artwork typography
    |--------------------------------------------------------------------------
    |
    | Optional TrueType serif used to measure label widths while generating
    | artwork. Falls back to a built-in width table when unavailable.
    |
    */

    'artwork_font' => env('ARTWORK_FONT', null),

    /*
    |--------------------------------------------------------------------------
    | Catalogue
    |--------------------------------------------------------------------------
    */

    'catalog' => [
        'per_page' => 12,
        'admin_per_page' => 20,
        'related_limit' => 4,
        'min_price' => 0,
        'max_price' => 5000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Seeded admin
    |--------------------------------------------------------------------------
    |
    | Read through config rather than env() in the seeder: env() returns null
    | once `php artisan optimize` has cached the configuration, which would
    | make seeding fail with "ADMIN_PASSWORD is not configured" on exactly the
    | host where the password matters most.
    |
    */

    'admin' => [
        'email' => env('ADMIN_EMAIL', 'admin@chamaperfumes.ma'),
        'password' => env('ADMIN_PASSWORD'),
    ],

];
