<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Support\StorefrontUrl;
use Illuminate\Http\Response;

/**
 * XML sitemap for the storefront.
 *
 * Served from the API host but built from the storefront origin, so every
 * <loc> names the page Google should index rather than the JSON endpoint. The
 * storefront's robots.txt points at this URL.
 *
 * The URL set is deliberately narrow: products, categories and brands, in every
 * locale, with the home and catalogue pages. Cart, checkout, order confirmation
 * and search are absent because they have nothing to index and no URL a
 * stranger could guess.
 */
class SitemapController extends Controller
{
    public function __invoke(): Response
    {
        $locales = array_keys(config('chamma.locales'));

        $urls = [];

        foreach ($locales as $locale) {
            $alternates = [];

            foreach ($locales as $alternate) {
                $alternates[$alternate] = StorefrontUrl::to("/{$alternate}");
            }

            $urls[] = $this->entry(StorefrontUrl::to("/{$locale}"), now(), 1.0, $alternates);
            $urls[] = $this->entry(
                StorefrontUrl::to("/{$locale}/products"),
                now(),
                0.8,
                $this->listingAlternates($locales, 'products'),
            );
        }

        $products = Product::query()
            ->active()
            ->with('translations')
            ->get(['id', 'slug', 'updated_at']);

        foreach ($products as $product) {
            foreach ($locales as $locale) {
                $path = "/{$locale}/products/".$product->slug($locale);
                $alternates = [];

                foreach ($locales as $alternate) {
                    $alternates[$alternate] = StorefrontUrl::to("/{$alternate}/products/".$product->slug($alternate));
                }

                $urls[] = $this->entry(
                    StorefrontUrl::to($path),
                    $product->updated_at ?? now(),
                    0.7,
                    $alternates,
                );
            }
        }

        $categories = Category::query()
            ->where('is_active', true)
            ->get(['id', 'slug', 'updated_at']);

        foreach ($categories as $category) {
            $alternates = [];

            foreach ($locales as $alternate) {
                $alternates[$alternate] = StorefrontUrl::to("/{$alternate}/categories/".$category->slug);
            }

            foreach ($locales as $locale) {
                $urls[] = $this->entry(
                    StorefrontUrl::to("/{$locale}/categories/".$category->slug),
                    $category->updated_at ?? now(),
                    0.6,
                    $alternates,
                );
            }
        }

        $brands = Brand::query()
            ->where('is_active', true)
            ->get(['id', 'slug', 'updated_at']);

        foreach ($brands as $brand) {
            $alternates = [];

            foreach ($locales as $alternate) {
                $alternates[$alternate] = StorefrontUrl::to("/{$alternate}/brands/".$brand->slug);
            }

            foreach ($locales as $locale) {
                $urls[] = $this->entry(
                    StorefrontUrl::to("/{$locale}/brands/".$brand->slug),
                    $brand->updated_at ?? now(),
                    0.5,
                    $alternates,
                );
            }
        }

        return response()
            ->view('sitemap', ['urls' => $urls])
            ->header('Content-Type', 'application/xml; charset=utf-8');
    }

    /**
     * Alternates for a listing page that has no per-locale data of its own —
     * home and the shop index are the same page in every language.
     *
     * @param  array<int, string>  $locales
     * @return array<string, string>
     */
    private function listingAlternates(array $locales, string $segment = ''): array
    {
        $paths = [];

        foreach ($locales as $locale) {
            $paths[$locale] = StorefrontUrl::to($segment === '' ? "/{$locale}" : "/{$locale}/{$segment}");
        }

        return $paths;
    }

    /**
     * One <url>. hreflang alternates travel inside xhtml:link, which is how a
     * sitemap tells Google the translations are the same page rather than
     * three competing ones.
     */
    private function entry(string $loc, mixed $lastmod, float $priority = 0.5, ?array $alternates = null): string
    {
        $xml = '  <url>'."\n";
        $xml .= '    <loc>'.e($loc).'</loc>'."\n";
        $xml .= '    <lastmod>'.($lastmod instanceof \DateTimeInterface
            ? $lastmod->format('Y-m-d')
            : e((string) $lastmod)).'</lastmod>'."\n";
        $xml .= '    <priority>'.number_format((float) $priority, 1).'</priority>'."\n";

        foreach ((array) ($alternates ?? []) as $hreflang => $href) {
            $xml .= '    <xhtml:link rel="alternate" hreflang="'.e($hreflang).'" href="'.e($href).'"/>'."\n";
        }

        $xml .= '    <changefreq>weekly</changefreq>'."\n";
        $xml .= '  </url>'."\n";

        return $xml;
    }
}