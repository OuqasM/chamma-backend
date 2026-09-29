<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

/**
 * `php artisan db:seed` is run on the live host. The demo catalogue and its
 * orders are development data: seeded into a real database they would be
 * invented products and invented customers. Production must end up with the
 * admin account and nothing else, while local development keeps the browsable
 * store the container entrypoint relies on.
 */
class SeedingTest extends TestCase
{
    use RefreshDatabase;

    private string $artwork;

    protected function setUp(): void
    {
        parent::setUp();

        // The artwork is generated onto the storefront disk; keep it out of
        // public/images so a test run leaves no files behind.
        $this->artwork = storage_path('framework/testing/artwork');
        File::ensureDirectoryExists($this->artwork);
        config(['filesystems.disks.storefront.root' => $this->artwork]);

        $this->withAdminPassword('testing-only-password');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->artwork);

        unset($_ENV['ADMIN_PASSWORD'], $_SERVER['ADMIN_PASSWORD']);
        putenv('ADMIN_PASSWORD');

        parent::tearDown();
    }

    private function withAdminPassword(?string $password): void
    {
        if ($password === null) {
            config(['chamma.admin.password' => null]);

            return;
        }

        config(['chamma.admin.password' => $password]);
    }

    public function test_production_seeds_only_the_admin_account(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

        $this->assertSame(1, User::count(), 'Only the admin account should exist.');

        $admin = User::first();
        $this->assertTrue($admin->is_admin);
        $this->assertSame(0, Product::count(), 'No demo products may reach production.');
        $this->assertSame(0, Order::count(), 'No demo orders may reach production.');
    }

    public function test_development_still_seeds_the_demo_catalogue(): void
    {
        $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

        $this->assertGreaterThan(0, Product::count());
        $this->assertGreaterThan(0, Order::count());
    }

    /**
     * The admin password is a live credential, so seeding without one has to
     * fail loudly rather than create a known account.
     */
    public function test_seeding_without_a_password_fails(): void
    {
        $this->withAdminPassword(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ADMIN_PASSWORD is not configured');

        $this->artisan('db:seed', ['--force' => true])->run();
    }

    public function test_the_password_is_read_through_config_not_env(): void
    {
        // A cached configuration makes env() return null, which used to break
        // seeding on any host that ran `php artisan optimize` first.
        config(['chamma.admin.password' => 'a-cached-password']);

        $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

        $this->assertTrue(
            User::where('email', config('chamma.admin.email'))->exists()
        );
    }
}
