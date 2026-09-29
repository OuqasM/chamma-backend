<?php

namespace Database\Seeders;

use App\Services\ImageLibrary;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(AdminUserSeeder::class);

        // The catalogue and its orders exist so that local development has
        // something realistic to look at. On a real host they would be
        // invented products and invented customers sitting in the live
        // database, so production stops here: the admin account is created and
        // the store is filled through the admin panel.
        if (app()->environment('production')) {
            $this->command?->newLine();
            $this->command?->info('Admin account seeded. The demo catalogue and its orders were skipped; add products through the admin panel.');

            return;
        }

        $this->call([
            StoreCatalogSeeder::class,
            OrderSeeder::class,
        ]);

        $this->seedEditorialArtwork();

        $this->command?->newLine();
        $this->command?->info('Chamma Store is ready. Storefront: http://localhost:5173');
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
