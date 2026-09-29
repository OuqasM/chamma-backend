<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;
use Illuminate\Testing\TestResponse;

/**
 * The storefront is built by Vite and copied into public/ on the host. These
 * tests cover what the framework answers for paths that belong to that
 * application, and guard the one thing that would break the API if it were
 * ever handled here instead.
 */
class StorefrontFallbackTest extends TestCase
{
    private string $index;

    /** Files created by a test, removed again so nothing is left in public/. */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->index = public_path('index.html');
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created) as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->created = [];

        parent::tearDown();
    }

    /**
     * response()->file() hands back a BinaryFileResponse, whose body is not
     * available as content on the test response, so read the file it points at.
     */
    private function body(TestResponse $response): string
    {
        $base = $response->baseResponse;

        if ($base instanceof BinaryFileResponse) {
            return (string) file_get_contents($base->getFile()->getPathname());
        }

        return (string) $response->getContent();
    }

    private function publishStorefront(): void
    {
        file_put_contents($this->index, '<!doctype html><html><body><div id="root"></div></body></html>');
        $this->created[] = $this->index;
    }

    private function publishAsset(string $relative, string $contents): string
    {
        $path = public_path($relative);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        file_put_contents($path, $contents);
        $this->created[] = $path;

        return $path;
    }

    public function test_root_serves_the_storefront(): void
    {
        $this->publishStorefront();

        $response = $this->get('/');

        $response->assertOk();
        $this->assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('id="root"', $this->body($response));
    }

    /**
     * Every client side route of the SPA has to resolve to the same document,
     * otherwise a refresh on /checkout returns 404.
     */
    #[DataProvider('clientRoutes')]
    public function test_client_side_routes_resolve_to_the_storefront(string $path): void
    {
        $this->publishStorefront();

        $response = $this->get($path)->assertOk();

        $this->assertStringContainsString('id="root"', $this->body($response));
    }

    public static function clientRoutes(): array
    {
        return [
            'checkout' => ['/checkout'],
            'product' => ['/product/miss-forever'],
            'cart' => ['/cart'],
            'deep nested path' => ['/category/parfum/checkout'],
        ];
    }

    public function test_built_assets_are_served_with_their_own_contents(): void
    {
        $this->publishAsset('assets/app-test.js', 'console.log("built");');

        $response = $this->get('/assets/app-test.js');

        $response->assertOk();
        $this->assertSame('console.log("built");', $this->body($response));
    }

    /**
     * The fallback must never read a PHP file from disk and return its source.
     */
    public function test_php_files_are_never_served_as_source(): void
    {
        $this->publishStorefront();

        $response = $this->get('/index.php');

        $this->assertStringNotContainsString('<?php', $this->body($response));
    }

    public function test_unknown_api_paths_still_answer_json(): void
    {
        $this->publishStorefront();

        $this->getJson('/api/fr/does-not-exist')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json');
    }

    public function test_the_api_is_not_shadowed_by_the_fallback(): void
    {
        $this->publishStorefront();

        $response = $this->get('/api/store');

        $response->assertOk();
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
    }

    public function test_health_route_is_unaffected(): void
    {
        $this->publishStorefront();

        $this->get('/up')->assertOk();
    }

    public function test_write_methods_do_not_fall_back_to_the_storefront(): void
    {
        $this->publishStorefront();

        // No POST route matches, so Laravel refuses the method outright
        // rather than handing back a document.
        $this->post('/nowhere')->assertStatus(405);
    }

    public function test_a_missing_storefront_explains_how_to_build_it(): void
    {
        $response = $this->get('/');

        $response->assertStatus(503);
        $response->assertJsonPath('message', 'The storefront has not been built yet.');
        $this->assertSame(url('/api/store'), $response->json('api'));
        $this->assertStringContainsString('npm run build', $response->getContent());
    }

    public function test_the_index_is_never_cached(): void
    {
        $this->publishStorefront();

        $cacheControl = (string) $this->get('/')->headers->get('Cache-Control');

        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('no-cache', $cacheControl);
    }
}
