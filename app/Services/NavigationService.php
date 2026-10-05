<?php

namespace App\Services;

use App\Concerns\ReadsTranslatedTaxonomy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The storefront shell's bootstrap payload: the brand and category lists the
 * header and footer are built from, plus the store's contact details.
 *
 * Two decisions here, both about what this endpoint is *for*.
 *
 * **It is a menu, not an index.** The mega sheet, the drawer and the footer read
 * six fields between them — id, slug, name, tagline, and the category photo. The
 * SEO resource each taxonomy uses elsewhere also carries `canonical`,
 * `alternates`, `url` and `seo`, which on a 12-brand, 12-category shop measured
 * 62% of the response and that nothing here reads. Those fields belong to
 * `/brands` and `/categories`, which *are* indexable; repeating them in the shell
 * bootstrap means every page view pays for metadata it will never use. So this
 * selects the columns it needs in SQL rather than reusing `BrandResource`.
 *
 * **It is not a page.** The shell refetches it on a cold load and on every
 * language change, but the answer only changes when the owner edits a brand or a
 * category, so it is cached per locale and flushed from the admin writes that can
 * actually change it. That is also why it is not on the `track` middleware:
 * counting an API bootstrap as a page view put a `navigation` row in the visitor
 * table for every visitor on every page.
 *
 * Selecting the translated name in SQL rather than hydrating the models is the
 * other half of it: the model route loads *every* translation row of every
 * taxonomy and then walks the fallback chain per entity in PHP. One left join per
 * candidate locale does the same work in the database.
 */
class NavigationService
{
    use ReadsTranslatedTaxonomy;

    /**
     * Long enough to absorb a browsing session, short enough that an edit shows
     * up on its own even if a flush were ever missed.
     */
    private const TTL_SECONDS = 900;

    public function payload(string $locale): array
    {
        return Cache::remember(
            $this->cacheKey($locale),
            self::TTL_SECONDS,
            fn () => [
                'brands' => $this->brands($locale),
                'categories' => $this->categories($locale),
                'contact' => app(StoreContactService::class)->contact($locale),
            ],
        );
    }

    /**
     * Drop the cached shell payload for every locale.
     *
     * Called from the admin writes. A brand renamed in French changes the Arabic
     * menu too, because both read the same row, so this cannot be per-locale and
     * the loop is the honest way to say so.
     */
    public function flush(): void
    {
        foreach (array_keys(config('chamma.locales')) as $locale) {
            Cache::forget($this->cacheKey($locale));
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function brands(string $locale): array
    {
        $chain = $this->localeChain($locale);
        $aliases = $this->translationAliases($chain);

        $query = $this->joinTranslationChain(DB::table('brands'), 'brands', 'brand_translations', 'brand_id', $chain, $aliases)
            ->where('brands.is_active', true)
            // `Brand::scopeOrdered()`: position first, then the canonical name.
            ->orderBy('brands.position')
            ->orderBy('brands.name');

        $columns = ['brands.id', 'brands.slug', 'brands.logo', 'brands.name as canonical_name'];

        foreach ($aliases as $alias) {
            $columns[] = "$alias.name as {$alias}_name";
            $columns[] = "$alias.tagline as {$alias}_tagline";
        }

        return $query->get($columns)->map(fn ($row) => [
            'id' => $row->id,
            'slug' => $row->slug,
            // Translations first, in chain order, with the canonical column as the
            // last resort — the same precedence `translated()` applies. A brand
            // with no translation row at all still shows its latin name here,
            // which is what the model does too.
            'name' => $this->pick(...[...$this->translationValues($row, $aliases, 'name'), $row->canonical_name]),
            'tagline' => $this->pick(...$this->translationValues($row, $aliases, 'tagline')),
            'logo' => $row->logo ? Storage::disk(config('chamma.disk'))->url($row->logo) : null,
        ])->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function categories(string $locale): array
    {
        $chain = $this->localeChain($locale);
        $aliases = $this->translationAliases($chain);

        $query = $this->joinTranslationChain(DB::table('categories'), 'categories', 'category_translations', 'category_id', $chain, $aliases)
            ->where('categories.is_active', true)
            // `Category::scopeOrdered()`: position first, then id.
            ->orderBy('categories.position')
            ->orderBy('categories.id');

        $columns = ['categories.id', 'categories.slug', 'categories.image'];

        foreach ($aliases as $alias) {
            $columns[] = "$alias.name as {$alias}_name";
        }

        return $query->get($columns)->map(fn ($row) => [
            'id' => $row->id,
            'slug' => $row->slug,
            // Categories have no canonical name column, so unlike a brand there is
            // no last resort: an untranslated category has no name to show.
            'name' => $this->pick(...$this->translationValues($row, $aliases, 'name')),
            // Only the mega sheet reads this, as the category tile's photograph.
            'image' => $row->image
                ? ['url' => Storage::disk(config('chamma.disk'))->url($row->image)]
                : null,
        ])->all();
    }

    private function cacheKey(string $locale): string
    {
        return 'navigation:'.$locale;
    }
}
