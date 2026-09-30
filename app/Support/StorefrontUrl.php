<?php

namespace App\Support;

/**
 * Absolute storefront URLs for the pages search engines index.
 *
 * The API is served from api.chammastore.com and the storefront from
 * chammastore.com, so the API's own APP_URL is the wrong origin for anything
 * Google reads: a canonical pointing at the API host asks it to index the JSON
 * instead of the shop.
 *
 * The resource layer keeps emitting relative paths for `url`, because that is
 * what react-router links against and the browser resolves it against the
 * storefront for free. Only the fields a crawler consumes need an absolute
 * origin, and those are built here.
 */
final class StorefrontUrl
{
    /**
     * Absolute URL for a storefront path such as `/fr/products/oud`.
     */
    public static function to(string $path = '/'): string
    {
        $base = rtrim((string) config('chamma.storefront_url'), '/');

        if ($path === '' || $path === '/') {
            return $base.'/';
        }

        // Already absolute: an image CDN or an explicitly stored full URL.
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        return $base.'/'.self::encode($path);
    }

    /**
     * Percent-encodes each path segment, leaving the separators alone.
     *
     * A translated slug can be Arabic. Written raw it is valid XML, but a URL
     * in a sitemap and in rel=canonical is expected to be escaped, and an
     * unescaped space or hash in a slug would truncate the URL outright.
     */
    private static function encode(string $path): string
    {
        return implode('/', array_map(
            static fn (string $segment): string => rawurlencode(rawurldecode($segment)),
            explode('/', ltrim($path, '/')),
        ));
    }

    /**
     * Turns the relative alternates map into absolute hreflang targets.
     *
     * @param  array<string, string>  $alternates
     * @return array<string, string>
     */
    public static function alternates(array $alternates): array
    {
        return array_map(static fn (string $path): string => self::to($path), $alternates);
    }

    /**
     * The origin on its own, for Open Graph and JSON-LD ids.
     */
    public static function origin(): string
    {
        return rtrim((string) config('chamma.storefront_url'), '/');
    }
}