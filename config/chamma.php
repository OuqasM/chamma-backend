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
        // Digits only, no `+` and no separators: the storefront builds
        // `https://wa.me/<digits>`. This is the same line the payment
        // instructions send transfer receipts to, so there is one number for
        // the whole store rather than two that can drift apart.
        'whatsapp' => env('STORE_WHATSAPP', '212773214703'),
        // Handles, not URLs. See StoreContactService.
        'instagram' => env('STORE_INSTAGRAM', 'chamma_store_'),
        'facebook' => env('STORE_FACEBOOK', ''),
        'tiktok' => env('STORE_TIKTOK', 'angelofheaven88'),
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
    | `whatsapp` falls back to the store's number so receipts and the footer
    | link cannot drift onto two different numbers. Set STORE_PAYMENT_WHATSAPP
    | only to override it deliberately.
    |
    */

    'payment' => [
        'methods' => ['cod', 'bank_transfer'],
        'default' => 'cod',
        'whatsapp' => env('STORE_PAYMENT_WHATSAPP', env('STORE_WHATSAPP', '212773214703')),
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
        // Free-shipping threshold, in MAD. 0 disables free shipping entirely,
        // which is the default: the store's own carrier tariff is the price a
        // shopper is quoted, and silently replacing it with "Offerte" above an
        // arbitrary basket size undercuts the per-city fees that the checkout
        // picker displays. Set STORE_FREE_SHIPPING_THRESHOLD to opt back in.
        'free_threshold' => (float) env('STORE_FREE_SHIPPING_THRESHOLD', 0),
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
    | Upload optimization
    |--------------------------------------------------------------------------
    |
    | Raster uploads are downscaled and re-encoded before being stored, so the
    | storefront never serves a raw phone-camera original (multi-megapixel
    | files were the main cause of slow page loads). Longest side is capped at
    | max_dimension pixels; JPEGs are always re-encoded at `quality`.
    |
    */

    'uploads' => [
        'max_dimension' => (int) env('UPLOAD_MAX_DIMENSION', 1920),
        'quality' => (int) env('UPLOAD_QUALITY', 85),
    ],

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
    | Page view log
    |--------------------------------------------------------------------------
    |
    | A page view carries the visitor's IP address, so the log is personal
    | data with a retention window: `model:prune` (scheduled daily in
    | routes/console.php) deletes rows older than this many days. Set
    | STORE_VISIT_RETENTION_DAYS to opt for a different window.
    |
    */

    'visits' => [
        'retention_days' => (int) env('STORE_VISIT_RETENTION_DAYS', 365),
    ],

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

        // The page sizes a catalogue listing may be cached at.
        //
        // The listing cache is keyed by the listing's state — the locale, the
        // sort, the page size and the page — and every one of those dimensions
        // has to be bounded, because an open-ended one is an unbounded number
        // of cache keys. The page size arrives on the query string, so it is
        // only cacheable at the sizes the shop actually uses: the API's own
        // default, and the larger one the storefront asks for so that a page
        // of results is a page of results rather than the first screenful of
        // a long list.
        'cacheable_per_page' => [12, 24],

        // How many pages of a listing are cached.
        //
        // The first pages are the ones everybody reads. A shopper on the
        // twentieth page knows exactly what they are looking for, and is rare
        // enough that caching their page would only grow the table.
        'cacheable_pages' => 10,
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

    /*
    |--------------------------------------------------------------------------
    | Public storefront origin
    |--------------------------------------------------------------------------
    |
    | The host the *storefront* is served from, which is not the API's own
    | APP_URL: the API lives on api.chammastore.com while the pages Google
    | indexes live on chammastore.com. Canonicals, hreflang alternates, sitemap
    | entries and JSON-LD ids are all storefront URLs, so they have to be built
    | from this rather than from APP_URL.
    |
    */

    'storefront_url' => rtrim((string) env('STOREFRONT_URL', 'https://chammastore.com'), '/'),

    'admin' => [
        'email' => env('ADMIN_EMAIL', 'admin@chamaperfumes.ma'),
        'password' => env('ADMIN_PASSWORD'),
    ],

];
