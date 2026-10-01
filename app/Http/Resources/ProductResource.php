<?php

namespace App\Http\Resources;

use App\Models\Product;
use App\Support\Markdown;
use App\Support\StorefrontUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;

/**
 * Storefront representation of a product: already translated into the
 * requested locale, with the alternates needed for hreflang tags.
 */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $locale = App::getLocale();
        $image = $this->primaryImage();
        $short = $this->translated('short_description', $locale);
        $description = $this->description($locale);

        return [
            'description_translations' => $this->descriptionTranslations($locale),
            'id' => $this->id,
            'slug' => $this->slug($locale),
            'canonical_slug' => $this->slug,
            'name' => $this->name($locale),
            // Raw markdown for the admin form and for anything that needs the
            // source, alongside the rendered HTML the storefront displays.
            'short_description' => $short,
            'short_description_html' => Markdown::toHtml($short),
            'description' => $description,
            'description_html' => Markdown::toHtml($description),
            'sku' => $this->sku,
            'size' => $this->size,
            'gender' => $this->gender,

            'price' => (float) $this->price,
            'compare_at_price' => $this->compare_at_price !== null ? (float) $this->compare_at_price : null,
            'discount_percent' => $this->discount_percent,
            'is_on_sale' => $this->is_on_sale,

            'stock' => $this->stock,
            'in_stock' => $this->in_stock,
            'is_low_stock' => $this->is_low_stock,

            'rating' => (float) $this->rating,
            'rating_count' => (int) $this->rating_count,
            'sales_count' => (int) $this->sales_count,
            'is_featured' => (bool) $this->is_featured,
            'created_at' => $this->created_at?->toIso8601String(),

            'brand' => $this->whenLoaded('brand', fn () => $this->brand ? [
                'id' => $this->brand->id,
                'slug' => $this->brand->slug,
                'name' => $this->brand->name($locale),
            ] : null),

            // The storefront shows every category the product belongs in. The
            // singular `category` alongside it is the first of that list, kept so
            // an older bundle keeps rendering a breadcrumb while the two halves
            // of a deploy are on different versions.
            'categories' => $this->whenLoaded('categories', fn () => $this->categories->map(fn ($category) => [
                'id' => $category->id,
                'slug' => $category->slug,
                'name' => $category->name($locale),
            ])->values()),

            'category' => $this->whenLoaded('categories', fn () => $this->categories->first() ? [
                'id' => $this->categories->first()->id,
                'slug' => $this->categories->first()->slug,
                'name' => $this->categories->first()->name($locale),
            ] : null),

            // width/height are null unless the file could actually be read,
            // rather than a fixed guess: the storefront reserves space from
            // these, and a wrong ratio shifts the layout on every product page.
            'image' => $image ? array_filter([
                'url' => $image->url,
                'alt' => $image->alt ?: $this->name($locale),
                'dimensions' => $image->dimensions(),
            ], static fn ($v) => $v !== null) : null,

            'images' => $this->whenLoaded('images', fn () => $this->images->map(fn ($i) => [
                'url' => $i->url,
                'alt' => $i->alt ?: $this->name($locale),
                'is_primary' => (bool) $i->is_primary,
            ])->values()),

            'url' => '/'.$locale.'/products/'.$this->slug($locale),
            // Relative, for react-router links. The two below are absolute,
            // because a canonical and an hreflang that a crawler reads have to
            // name the storefront host, not the API it was served from.
            'canonical' => StorefrontUrl::to('/'.$locale.'/products/'.$this->slug($locale)),
            'alternates' => $this->alternates(),

            'seo' => [
                'title' => $this->translated('meta_title', $locale) ?: $this->name($locale),
                // Rendered copy is reduced back to plain text, so markdown syntax
                // never reaches the meta description.
                'description' => $this->translated('meta_description', $locale)
                    ?: Str::limit(Markdown::toPlainText($description), 160),
            ],
        ];
    }

    /**
     * This product's description in every language that has one of its own,
     * for the "read this in another language" control on the product page.
     *
     * Read straight off the translation rows rather than through
     * HasTranslations::translated(), and that is the whole point. translated()
     * walks a fallback chain — requested, then default, then fallback — so
     * asking it for Arabic on a product that was only ever written in French
     * returns the French text. A shopper who does not read French, shown a
     * paragraph of it under an Arabic heading, is worse off than one shown
     * nothing: it looks like the store wrote that and cannot be trusted to
     * describe its own product.
     *
     * So only rows with a description of their own qualify, the language is
     * named in that language from the same locale table the rest of the store
     * uses, and the current locale is left out because the page already shows
     * it. Empty for a product nobody has translated, which is what the control
     * needs to know in order not to offer anything.
     *
     * @return array<int, array{locale: string, name: string, dir: string, description: string, description_html: string}>
     */
    private function descriptionTranslations(string $currentLocale): array
    {
        $locales = config('chamma.locales');

        $available = [];

        foreach ($this->translations as $translation) {
            $code = (string) $translation->locale;
            $text = (string) $translation->description;

            // Unknown locale, the one already on screen, or copy that was never
            // written: nothing to offer.
            if (! isset($locales[$code]) || $code === $currentLocale || trim($text) === '') {
                continue;
            }

            $available[] = [
                'locale' => $code,
                'name' => $locales[$code]['native'],
                'dir' => $locales[$code]['dir'],
                'description' => $text,
                'description_html' => Markdown::toHtml($text),
            ];
        }

        // A predictable order, so the control does not reshuffle between two
        // loads of the same product.
        usort($available, fn (array $a, array $b) => array_search($a['locale'], array_keys($locales), true)
            <=> array_search($b['locale'], array_keys($locales), true));

        return $available;
    }

    /**
     * Localised URL per language — powers <link rel="alternate" hreflang>.
     *
     * @return array<string, string>
     */
    private function alternates(): array
    {
        $paths = [];

        foreach (array_keys(config('chamma.locales')) as $locale) {
            $paths[$locale] = StorefrontUrl::to('/'.$locale.'/products/'.$this->slug($locale));
        }

        return $paths;
    }
}
