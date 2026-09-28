<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\ImageLibrary;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Builds the whole demo catalogue: brands, categories, products, translations
 * and generated imagery.
 *
 * Idempotent by design — it is safe to run on every container boot, so a fresh
 * `docker compose up -d` always yields a browsable store.
 */
class StoreCatalogSeeder extends Seeder
{
    public function __construct(private readonly ImageLibrary $images) {}

    public function run(): void
    {
        $brands = $this->seedBrands();
        $categories = $this->seedCategories();
        $this->seedProducts($brands, $categories);

        $this->command?->info(sprintf(
            '  Catalogue: %d brands, %d categories, %d products',
            $brands->count(),
            $categories->count(),
            Product::query()->count(),
        ));
    }

    /**
     * @return \Illuminate\Support\Collection<string, Brand>
     */
    private function seedBrands(): \Illuminate\Support\Collection
    {
        $brands = collect();

        foreach (CatalogData::brands() as $record) {
            // `firstOrNew` rather than `updateOrCreate`: the seed attributes are
            // refreshed on every boot, but the logo is only ever assigned when
            // the row is first created. Re-seeding must never clobber a logo
            // the admin uploaded.
            $brand = Brand::query()->firstOrNew(['slug' => $record['slug']]);

            $brand->fill([
                'name' => $record['name'],
                'origin' => $record['origin'],
                'position' => $record['position'],
                'is_active' => true,
            ]);

            if (! $brand->exists) {
                $brand->logo = $this->images->brandLogo($record['slug'], $record['name'], $record['tone']);
            }

            $brand->save();

            foreach ($record['translations'] as $locale => $translation) {
                $brand->translations()->updateOrCreate(
                    ['locale' => $locale],
                    $translation,
                );
            }

            $brands->put($record['key'], $brand);
        }

        return $brands;
    }

    /**
     * @return \Illuminate\Support\Collection<string, Category>
     */
    private function seedCategories(): \Illuminate\Support\Collection
    {
        $categories = collect();

        foreach (CatalogData::categories() as $record) {
            // As with brands, the image is only seeded on creation so an
            // uploaded category photo survives a re-seed.
            $category = Category::query()->firstOrNew(['slug' => $record['slug']]);

            $category->fill([
                'position' => $record['position'],
                'is_active' => true,
            ]);

            if (! $category->exists) {
                $category->image = $this->images->collectionArtwork($record['slug'], $record['art']);
            }

            $category->save();

            foreach ($record['translations'] as $locale => $translation) {
                $category->translations()->updateOrCreate(
                    ['locale' => $locale],
                    $translation,
                );
            }

            $categories->put($record['key'], $category);
        }

        return $categories;
    }

    /**
     * @param  \Illuminate\Support\Collection<string, Brand>  $brands
     * @param  \Illuminate\Support\Collection<string, Category>  $categories
     */
    private function seedProducts(\Illuminate\Support\Collection $brands, \Illuminate\Support\Collection $categories): void
    {
        foreach (CatalogData::products() as $record) {
            $slug = Str::slug($record['sku']);

            $product = Product::query()->updateOrCreate(
                ['sku' => $record['sku']],
                [
                    'brand_id' => $brands->get($record['brand'])?->id,
                    'category_id' => $categories->get($record['category'])?->id,
                    'slug' => $slug,
                    'price' => $record['price'],
                    'compare_at_price' => $record['compare_at'],
                    'stock' => $record['stock'],
                    'size' => $record['size'],
                    'gender' => $record['gender'],
                    'is_active' => true,
                    'is_featured' => $record['featured'],
                    'is_new' => $record['new'],
                    'rating' => $record['rating'],
                    'rating_count' => $record['rating_count'],
                    'sales_count' => $record['sales'],
                ],
            );

            // Localised slug + copy.
            $seen = [];

            foreach (['fr', 'ar', 'en'] as $locale) {
                $localized = $this->localizedSlug(
                    $record['names'][$locale],
                    $locale,
                    $seen,
                );

                $product->translations()->updateOrCreate(
                    ['locale' => $locale],
                    [
                        'slug' => $localized,
                        'name' => $record['names'][$locale],
                        'short_description' => $record['short'][$locale],
                        'description' => $record['description'][$locale],
                        'meta_title' => $record['names'][$locale].' | '.config('chamma.name'),
                        'meta_description' => Str::limit($record['short'][$locale], 155),
                    ],
                );
            }

            // Gallery: one primary shot plus two supporting angles.
            if ($product->images()->count() === 0) {
                $gallery = $this->images->productGallery($slug, [
                    'shape' => $record['shape'],
                    'tone' => $record['tone'],
                    'size' => $record['size'],
                    'label' => $record['names']['fr'],
                ]);

                foreach ($gallery as $index => $path) {
                    $product->images()->create([
                        'path' => $path,
                        'alt' => $record['names']['fr'],
                        'is_primary' => $index === 0,
                        'position' => $index,
                    ]);
                }
            }
        }
    }

    /**
     * Each localised slug must be unique per locale, and /fr, /ar, /en may all
     * differ, so collisions get a numeric suffix.
     *
     * @param  array<string, string>  $seen
     */
    private function localizedSlug(string $name, string $locale, array &$seen): string
    {
        $base = CatalogData::slugify($name, $locale);
        $slug = $base;
        $suffix = 2;

        while (isset($seen[$slug])) {
            $slug = $base.'-'.$suffix++;
        }

        return $seen[$slug] = $slug;
    }
}
