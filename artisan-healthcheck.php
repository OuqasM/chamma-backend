<?php

/**
 * Container healthcheck: boots the framework and hits the public API so a
 * failing database or broken configuration marks the service unhealthy.
 */
require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    $locale = config('chamma.default_locale');
    $response = Illuminate\Support\Facades\Http::timeout(5)
        ->get(url("/api/{$locale}/navigation"));

    exit($response->successful() ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    exit(1);
}
