<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * `php artisan optimize` is what an operator runs at the end of a deployment,
 * and its view step fails hard when a required directory is missing from the
 * checkout. Git does not track empty directories, so a directory that holds
 * nothing but compiled output silently disappears from a fresh clone — which
 * is exactly how `resources/views/` reached a shared host without a view path.
 *
 * Each directory below must therefore contain at least one tracked file, which
 * is what makes it survive a clone.
 */
class DeploymentReadinessTest extends TestCase
{
    /**
     * @return array<string, string> label => path relative to the project root
     */
    public static function requiredDirectories(): array
    {
        $root = dirname(__DIR__, 2);

        return [
            'resources/views' => $root.'/resources/views',
            'storage/app/public' => $root.'/storage/app/public',
            'storage/framework/cache' => $root.'/storage/framework/cache',
            'storage/framework/sessions' => $root.'/storage/framework/sessions',
            'storage/framework/views' => $root.'/storage/framework/views',
            'storage/logs' => $root.'/storage/logs',
            'bootstrap/cache' => $root.'/bootstrap/cache',
        ];
    }

    public function test_every_required_directory_would_survive_a_fresh_checkout(): void
    {
        $broken = [];

        foreach (self::requiredDirectories() as $label => $path) {
            if (! is_dir($path)) {
                $broken[] = "{$label}: does not exist";

                continue;
            }

            // An empty directory is not in git, so a clone would not have it.
            if (scandir($path) === ['.', '..']) {
                $broken[] = "{$label}: empty, so git does not track it — add a .gitkeep";
            }
        }

        $this->assertSame([], $broken, implode("\n", $broken));
    }

    public function test_view_cache_succeeds(): void
    {
        $this->artisan('view:cache')->assertSuccessful();
    }

    public function test_optimize_succeeds(): void
    {
        $this->artisan('optimize')->assertSuccessful();
    }

    protected function tearDown(): void
    {
        // Drop the caches this suite produced, then make sure the directories
        // that hold them still exist: view:clear only empties them.
        $this->artisan('optimize:clear');

        foreach (self::requiredDirectories() as $path) {
            if (! is_dir($path)) {
                mkdir($path, 0775, true);
            }
        }

        parent::tearDown();
    }
}
