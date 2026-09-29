<?php

namespace Tests\Feature;

use Fruitcake\Cors\CorsService;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The storefront is served from chammastore.com and reads the API from
 * api.chammastore.com, so every call is cross-origin and the browser preflights
 * it. Without these headers the storefront is dead on arrival: the catalogue,
 * the shipping options and the whole checkout all fail.
 */
class CorsTest extends TestCase
{
    /**
     * CorsService is resolved once and keeps the configuration it was built
     * with, so changing cors.allowed_origins in a test also has to drop the
     * resolved instance.
     *
     * @param  array<int, string>  $origins
     */
    private function allowOrigins(array $origins): void
    {
        config(['cors.allowed_origins' => $origins]);
        $this->app->forgetInstance(CorsService::class);
    }

    /** A simple cross-origin read, as the browser sends it. */
    private function read(string $origin): TestResponse
    {
        return $this->withHeaders(['Origin' => $origin])->get('/api/store');
    }

    /** The preflight the browser sends before a cross-origin write. */
    private function preflight(string $origin, string $method = 'POST', string $headers = 'authorization,content-type'): TestResponse
    {
        return $this->withHeaders([
            'Origin' => $origin,
            'Access-Control-Request-Method' => $method,
            'Access-Control-Request-Headers' => $headers,
        ])->options('/api/fr/checkout');
    }

    private function allowedOrigin(TestResponse $response): ?string
    {
        $value = $response->headers->get('Access-Control-Allow-Origin');

        return $value === null ? null : (string) $value;
    }

    public function test_the_storefront_origin_may_read_the_api(): void
    {
        $this->allowOrigins(['https://chammastore.com']);

        $this->read('https://chammastore.com')
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'https://chammastore.com');
    }

    public function test_both_storefront_hosts_are_allowed(): void
    {
        $this->allowOrigins(['https://chammastore.com', 'https://www.chammastore.com']);

        $this->assertSame('https://chammastore.com', $this->allowedOrigin($this->read('https://chammastore.com')));
        $this->assertSame('https://www.chammastore.com', $this->allowedOrigin($this->read('https://www.chammastore.com')));
    }

    /**
     * The browser blocks a response whose allowed origin is not its own, which
     * is what keeps a third party site from reading the API.
     */
    public function test_an_unlisted_origin_is_not_granted_access(): void
    {
        $this->allowOrigins(['https://chammastore.com']);

        $this->assertNotSame(
            'https://someone-else.example',
            $this->allowedOrigin($this->read('https://someone-else.example'))
        );
    }

    /**
     * The admin authenticates with a bearer token, so the preflight has to
     * allow the Authorization header or every admin request fails.
     */
    public function test_the_preflight_allows_the_authorization_header(): void
    {
        $this->allowOrigins(['https://chammastore.com']);

        $response = $this->preflight('https://chammastore.com');

        $this->assertSame('https://chammastore.com', $this->allowedOrigin($response));

        $allowedHeaders = (string) $response->headers->get('Access-Control-Allow-Headers');
        $this->assertStringContainsStringIgnoringCase('authorization', $allowedHeaders);

        $allowedMethods = (string) $response->headers->get('Access-Control-Allow-Methods');
        $this->assertStringContainsStringIgnoringCase('post', $allowedMethods);
    }

    /**
     * With nothing configured the API stays open, which is what local
     * development and a single origin deployment need.
     */
    public function test_an_unconfigured_api_allows_any_origin(): void
    {
        $this->read('https://chammastore.com')
            ->assertHeader('Access-Control-Allow-Origin', '*');
    }

    /**
     * CORS_ALLOWED_ORIGINS is what the host configures, so the parsing of it
     * is worth pinning down.
     */
    public function test_the_origins_are_parsed_from_the_environment(): void
    {
        putenv('CORS_ALLOWED_ORIGINS=https://chammastore.com, https://www.chammastore.com');

        try {
            $parsed = require config_path('cors.php');

            $this->assertSame(
                ['https://chammastore.com', 'https://www.chammastore.com'],
                $parsed['allowed_origins']
            );
        } finally {
            putenv('CORS_ALLOWED_ORIGINS');
        }
    }

    public function test_an_empty_origin_list_falls_back_to_any_origin(): void
    {
        putenv('CORS_ALLOWED_ORIGINS=');

        try {
            $this->assertSame(['*'], (require config_path('cors.php'))['allowed_origins']);
        } finally {
            putenv('CORS_ALLOWED_ORIGINS');
        }
    }
}
