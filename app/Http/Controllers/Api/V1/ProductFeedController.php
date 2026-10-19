<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\Markdown;
use App\Support\StorefrontUrl;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Google Merchant Center product feed: RSS 2.0 carrying the `g:` namespace.
 *
 * This is what Search Console's "Show your products on the Shopping tab"
 * prompt is about, and for this store — a custom build, nothing like Shopify —
 * the feed is the product data source. There is one URL per language,
 * /api/{locale}/products.xml, because Merchant Center judges a feed's language
 * and an item's page must answer in the language the feed promises.
 *
 * Every <link> and <g:image_link> points at the hosts the crawler can actually
 * reach: the storefront for product pages (StorefrontUrl), the API for the
 * pictures it serves from public/images. A canonical naming the API host would
 * hand Google JSON to index, the same mistake the sitemap avoids.
 *
 * Item ids are the database id and stay identical across the per-language
 * feeds — Merchant Center treats same-id items in different content languages
 * as translations of one product, which is exactly what they are.
 *
 * Not on the `track` middleware, deliberately: a feed read is a robot walking
 * the catalogue, not a shopper, and counting it would pollute analytics and
 * cost one visitor row per crawl.
 */
class ProductFeedController extends Controller
{
    /** Merchant Center drops the whole item when a title passes it. */
    private const TITLE_LENGTH = 150;

    private const DESCRIPTION_LENGTH = 5000;

    private const MAX_ADDITIONAL_IMAGES = 10;

    public function __invoke(): Response
    {
        $locale = app()->getLocale();
        $currency = (string) config('chamma.currency.code');

        $products = Product::query()
            ->active()
            ->with(['translations', 'images', 'brand.translations', 'categories.translations'])
            ->get();

        $items = $products
            ->map(fn (Product $product) => $this->item($product, $locale, $currency))
            ->all();

        return response()
            ->view('product-feed', [
                'title' => (string) config('chamma.name'),
                'link' => StorefrontUrl::origin(),
                'description' => 'Product listing for '.config('chamma.name'),
                'items' => $items,
            ])
            ->header('Content-Type', 'application/xml; charset=utf-8');
    }

    private function item(Product $product, string $locale, string $currency): string
    {
        $xml = '  <item>'."\n";
        $xml .= $this->tag('g:id', (string) $product->id);
        $xml .= $this->tag('g:title', Str::limit($product->name($locale), self::TITLE_LENGTH, ''));
        $xml .= $this->tag('g:description', Str::limit($this->description($product, $locale), self::DESCRIPTION_LENGTH, ''));
        $xml .= $this->tag('g:link', StorefrontUrl::to('/'.$locale.'/products/'.$product->slug($locale)));

        if (($image = $product->images->first()) !== null) {
            $xml .= $this->tag('g:image_link', $image->url);
        }

        foreach ($product->images->skip(1)->take(self::MAX_ADDITIONAL_IMAGES) as $extra) {
            $xml .= $this->tag('g:additional_image_link', $extra->url);
        }

        $xml .= $this->tag('g:availability', $product->in_stock ? 'in stock' : 'out of stock');

        // The regular price is what pairs with a sale price; the discounted
        // amount goes into g:sale_price, the pair Google reads as "this item
        // is on sale from X to Y".
        if ($product->is_on_sale) {
            $xml .= $this->tag('g:price', $this->money($product->compare_at_price, $currency));
            $xml .= $this->tag('g:sale_price', $this->money($product->price, $currency));
        } else {
            $xml .= $this->tag('g:price', $this->money($product->price, $currency));
        }

        if ($product->brand !== null) {
            $xml .= $this->tag('g:brand', $this->entityName($product->brand, $locale));
        }

        $xml .= $this->identification($product);
        $xml .= $this->tag('g:condition', 'new');

        if (($category = $product->categories->first()) !== null) {
            $xml .= $this->tag('g:product_type', $this->entityName($category, $locale));
        }

        $xml .= '  </item>'."\n";

        return $xml;
    }

    /**
     * Identifiers: the GTIN when the admin typed one, and an explicit "this
     * product has none" when they did not.
     *
     * The explicit absence matters as much as the code itself. Google
     * otherwise starts guessing and can marry our product to another shop's
     * barcode, mislisted with someone else's title and price — worse than
     * being absent at all.
     */
    private function identification(Product $product): string
    {
        $gtin = trim((string) $product->gtin);

        if ($gtin !== '') {
            return $this->tag('g:gtin', $gtin);
        }

        return $this->tag('g:identifier_exists', 'false');
    }

    /**
     * Plain, unformatted text of the full length the spec wants — the rendered
     * copy reduced back to prose, never markdown syntax.
     *
     * The attribute is required per item and the column can hold '' (the form
     * seeds it empty), so the chain falls to the short description and finally
     * to the name: an unparseable empty tag would take the whole feed down
     * with it.
     */
    private function description(Product $product, string $locale): string
    {
        foreach (['description', 'short_description'] as $attribute) {
            if (($text = $this->chainText($product, $locale, $attribute)) !== '') {
                return $text;
            }
        }

        return $product->name($locale);
    }

    /**
     * The first translation row in the locale chain that actually carries the
     * attribute, read back as plain text.
     *
     * Built on the same candidates as HasTranslations::translated() —
     * requested, then the configured default, then the fallback — but without
     * its last resort. That last resort hands back whichever row the query
     * happened to return first in whatever language it holds, which on a feed
     * is the mismatched-language copy Google penalises: the row order is the
     * database's choice, not the shop's. Google reads a feed's language off
     * its content, so an Arabic paragraph under a French title would flag the
     * item without helping the shopper the copy was meant for.
     */
    private function chainText(Product $product, string $locale, string $attribute): string
    {
        $candidates = array_values(array_unique(array_filter([
            $locale,
            config('chamma.default_locale'),
            config('chamma.fallback_locale'),
        ])));

        foreach ($candidates as $candidate) {
            $value = $product->translations->firstWhere('locale', $candidate)?->{$attribute} ?? null;

            if ($value !== null && trim((string) $value) !== '') {
                // Untranslated locales are not served either: an entity in the
                // chain whose copy is blank is treated as missing, the way the
                // storefront's fallback treats an empty string.
                return Markdown::toPlainText((string) $value);
            }
        }

        return '';
    }

    /**
     * The entity's name within the chain's languages, then the canonical
     * column as the last resort.
     *
     * A brand the admin typed once has its name on the row itself, the way the
     * navigation payload serves it; a category has only its translations, so a
     * category without a row in the chain yields no name at all rather than
     * whatever a language out of the chain had.
     */
    private function entityName(mixed $entity, string $locale): ?string
    {
        if ($entity === null) {
            return null;
        }

        $candidates = array_values(array_unique(array_filter([
            $locale,
            config('chamma.default_locale'),
            config('chamma.fallback_locale'),
        ])));

        foreach ($candidates as $candidate) {
            $value = $entity->translations->firstWhere('locale', $candidate)?->name ?? null;

            if ($value !== null && trim((string) $value) !== '') {
                return (string) $value;
            }
        }

        $canonical = $entity->getAttribute('name');

        return $canonical !== null && trim((string) $canonical) !== '' ? (string) $canonical : null;
    }

    /**
     * "890.00 MAD" — two decimals, a space, the ISO code the config owns.
     */
    private function money(mixed $amount, string $currency): string
    {
        return number_format(round((float) $amount, 2), 2, '.', '').' '.$currency;
    }

    /**
     * One XML line, omitted entirely when the value is empty.
     *
     * Merchant Center rejects an empty tag at parse time but reads a missing
     * one as "not set", and the optional attributes (images, brand, category)
     * are legitimately absent on some items.
     */
    private function tag(string $name, ?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return '    <'.$name.'>'.e($value).'</'.$name.'>'."\n";
    }
}
