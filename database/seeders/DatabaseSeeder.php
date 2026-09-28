<?php

namespace Database\Seeders;

use App\Services\ImageLibrary;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AdminUserSeeder::class,
            StoreCatalogSeeder::class,
            OrderSeeder::class,
        ]);

        $this->seedEditorialArtwork();

        $this->command?->newLine();
        $this->command?->info('Chamma Perfumes is ready. Storefront: http://localhost:5173');
    }

    /**
     * Home page hero + campaign banners. Generated once and reused afterwards.
     */
    private function seedEditorialArtwork(): void
    {
        $images = app(ImageLibrary::class);

        $images->heroArtwork();

        $images->bannerArtwork('signature', ['label' => 'SIGNATURE', 'tone' => 'espresso']);
        $images->bannerArtwork('ritual', ['label' => 'LE RITUEL', 'tone' => 'jade']);
        $images->bannerArtwork('gift', ['label' => 'CADEAUX', 'tone' => 'plum']);
    }
}
