<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
|
| The storefront is a Vite application. When it is served from this document
| root, its built files are copied into public/ and any path that is not an
| API route or a real file has to answer with index.html so React Router can
| resolve it client side.
|
| Note the exclusion below: routes/web.php is registered before the API in
| routes/api.php, so a wildcard here would claim /api/* and /up before the
| router ever looks at them.
|
*/

$storefront = function (Request $request) {
    $public = public_path();

    /*
     * Files that really exist in public/ are answered as they are, which
     * covers the built assets. Apache's rewrite already skips existing files,
     * but other entry points (artisan serve, PHP's built-in server) do not.
     *
     * Only known asset types are served: this must never read a .php file off
     * disk and hand back its source.
     */
    $assetTypes = [
        'avif', 'css', 'eot', 'gif', 'htm', 'html', 'ico', 'jpeg', 'jpg',
        'js', 'json', 'map', 'mjs', 'otf', 'png', 'svg', 'txt', 'webmanifest',
        'webp', 'woff', 'woff2',
    ];

    $requested = realpath($public.'/'.ltrim($request->path(), '/'));

    if ($requested !== false
        && str_starts_with($requested, $public.DIRECTORY_SEPARATOR)
        && is_file($requested)
        && in_array(strtolower(pathinfo($requested, PATHINFO_EXTENSION)), $assetTypes, true)
    ) {
        return response()->file($requested);
    }

    $index = $public.'/index.html';

    if (! is_file($index)) {
        return response()->json([
            'message' => 'The storefront has not been built yet.',
            'hint' => 'Run "npm ci && npm run build" in the frontend, then copy dist/* into backend/public/.',
            'api' => url('/api/store'),
            'health' => url('/up'),
        ], 503);
    }

    // Every deploy replaces index.html with one pointing at freshly hashed
    // assets, so it must never be answered from a stale cache.
    return response()->file($index, [
        'Content-Type' => 'text/html; charset=utf-8',
        'Cache-Control' => 'no-cache, no-store, must-revalidate',
    ]);
};

// api/ belongs to routes/api.php and up to the framework health route.
Route::get('/', $storefront);
Route::get('/{spaPath}', $storefront)
    ->where('spaPath', '^(?!api(?:/|$)|up$).*$');
