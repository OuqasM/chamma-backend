<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing
|--------------------------------------------------------------------------
|
| The storefront is served from its own host (chammastore.com) and reads this
| API from another (api.chammastore.com), so every API call is cross-origin
| and the browser preflights it.
|
| Origins are listed in CORS_ALLOWED_ORIGINS as a comma separated list. Left
| empty it falls back to any origin, which is what a single-origin deployment
| and local development want; set it in production so only the storefront is
| allowed. Credentials stay off: the admin authenticates with a bearer token
| from localStorage, never a cookie, so a wildcard origin leaks nothing.
|
*/

$origins = trim((string) env('CORS_ALLOWED_ORIGINS', ''));

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $origins === ''
        ? ['*']
        : array_values(array_filter(array_map('trim', explode(',', $origins)))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    // The storefront is served from its own host, so every API call is
    // cross-origin and the browser preflights it. With no max age each
    // preflight costs a full OPTIONS round trip that carries no data, and
    // a browsing session makes dozens of them. One day is the standard
    // cacheable answer: it covers a session, and a configuration change
    // still propagates to returning visitors the next day.
    'max_age' => 86400,

    'supports_credentials' => false,

];
