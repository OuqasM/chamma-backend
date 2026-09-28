<?php

namespace Tests\Unit;

use App\Support\Shipping\AtlasColisZoneFile;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The generated config is the store's delivery map: if this file is malformed,
 * the checkout breaks in production rather than at build time. These tests load
 * the rendered output the same way the framework does.
 */
class AtlasColisZoneFileTest extends TestCase
{
    private string $dir = '';

    private array $zones = [
        ['code' => 'BML', 'city' => 'Beni Mellal', 'fee' => 20.0, 'delay' => '8h', 'refusal_fee' => 0.0, 'return_fee' => 0.0],
        ['code' => 'CAS', 'city' => 'Casablanca', 'fee' => 35.0, 'delay' => null, 'refusal_fee' => 10.0, 'return_fee' => 0.0],
        ['code' => "Kelaat M'gouna", 'city' => "Kelaat M'gouna", 'fee' => 40.0, 'delay' => '24H', 'refusal_fee' => 0.0, 'return_fee' => 5.0],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/atlas-zones-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir);

        parent::tearDown();
    }

    private function writeTo(array $zones, string $name = 'zones.php'): string
    {
        $path = $this->dir.'/'.$name;
        file_put_contents($path, AtlasColisZoneFile::render($zones, 'https://atlascolis.ma/tarifs.php', '2026-09-28'));

        return $path;
    }

    public function test_the_rendered_file_loads_with_every_row_intact(): void
    {
        $path = $this->writeTo($this->zones);

        $this->assertSame(3, AtlasColisZoneFile::verify($path, 3));

        $data = require $path;

        $this->assertSame('https://atlascolis.ma/tarifs.php', $data['source']);
        $this->assertSame('2026-09-28', $data['fetched_at']);
        $this->assertCount(3, $data['zones']);
        $this->assertSame('Beni Mellal', $data['zones'][0]['city']);
        $this->assertSame(20, $data['zones'][0]['fee'], 'whole fees stay integers, not 20.0');
        $this->assertSame('8h', $data['zones'][0]['delay']);
        $this->assertNull($data['zones'][1]['delay'], 'an unknown delay must stay null');
        $this->assertSame(5, $data['zones'][2]['return_fee']);
    }

    public function test_the_rendered_file_starts_with_a_php_tag(): void
    {
        // A file without this tag lints clean and returns 1 instead of the
        // config array — the exact failure this guards against.
        $contents = AtlasColisZoneFile::render($this->zones, 'x', 'y');

        $this->assertStringStartsWith("<?php\n", $contents);

        $path = $this->dir.'/no-tag.php';
        file_put_contents($path, ltrim($contents, "<?php\n"));

        ob_start();
        $returned = require $path;
        ob_end_clean();

        $this->assertSame(1, $returned, 'sanity: dropping the tag breaks the file');

        $this->expectException(RuntimeException::class);
        AtlasColisZoneFile::verify($path, 3);
    }

    public function test_a_quoted_city_name_survives(): void
    {
        $path = $this->writeTo([
            ['code' => "M'HAYA", 'city' => "M'haya", 'fee' => 40.0, 'delay' => null, 'refusal_fee' => 10.0, 'return_fee' => 0.0],
            ['code' => 'BACK', 'city' => 'Beni \\ Mellal', 'fee' => 25.0, 'delay' => null, 'refusal_fee' => 0.0, 'return_fee' => 0.0],
        ]);

        $data = require $path;

        $this->assertSame("M'haya", $data['zones'][0]['city']);
        $this->assertSame('Beni \\ Mellal', $data['zones'][1]['city']);
        $this->assertSame(2, AtlasColisZoneFile::verify($path, 2));
    }

    public function test_a_row_count_mismatch_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expected 99');

        AtlasColisZoneFile::verify($this->writeTo($this->zones), 99);
    }

    public function test_a_row_without_a_fee_is_rejected(): void
    {
        $path = $this->dir.'/nofee.php';
        file_put_contents($path, "<?php\n\nreturn ['zones' => [['code' => 'X', 'city' => 'Nowhere', 'fee' => 0]]];\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no delivery fee');

        AtlasColisZoneFile::verify($path);
    }

    public function test_a_row_missing_keys_is_rejected(): void
    {
        $path = $this->dir.'/partial.php';
        file_put_contents($path, "<?php\n\nreturn ['zones' => [['city' => 'Nowhere', 'fee' => 20]]];\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing city/fee/code');

        AtlasColisZoneFile::verify($path);
    }

    public function test_a_file_that_is_not_a_config_is_rejected(): void
    {
        $path = $this->dir.'/other.php';
        file_put_contents($path, "<?php\n\nreturn 'not a config';\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('<?php tag');

        AtlasColisZoneFile::verify($path);
    }

    public function test_a_malformed_body_is_rejected(): void
    {
        $path = $this->dir.'/broken.php';
        file_put_contents($path, "<?php\n\nreturn ['zones' => [[\n");

        $this->expectException(RuntimeException::class);
        AtlasColisZoneFile::verify($path);
    }

    public function test_fractional_fees_render_without_trailing_zeros(): void
    {
        $path = $this->writeTo([
            ['code' => 'X', 'city' => 'Test', 'fee' => 20.5, 'delay' => null, 'refusal_fee' => 0.0, 'return_fee' => 0.0],
        ]);

        $data = require $path;

        $this->assertSame(20.5, $data['zones'][0]['fee']);
        $this->assertStringNotContainsString('20.50', file_get_contents($path));
    }
}
