<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the request locale and applies it for the whole request.
 *
 * The locale arrives as a middleware parameter (route prefix `fr`, `ar`, `en`),
 * which keeps every route down to a single path parameter — the framework's
 * controller argument resolution assumes that.
 *
 * Order of preference: URL > ?locale > Accept-Language > store default.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next, ?string $locale = null): Response
    {
        $supported = array_keys(config('chamma.locales'));

        $resolved = $locale
            ?: $request->query('locale')
            ?: $this->fromHeader($request, $supported);

        if (! in_array($resolved, $supported, true)) {
            $resolved = config('chamma.default_locale');
        }

        app()->setLocale($resolved);

        // Surfaced on the response so the SPA can confirm what it received.
        $request->attributes->set('locale', $resolved);

        $response = $next($request);

        if ($response instanceof Response) {
            // This middleware also runs globally, wrapped around the per-route
            // instance, and responses unwind inner-to-outer. Reading the app
            // locale at this point yields the *final* resolved value, so
            // whichever instance writes last still emits the right header.
            $response->headers->set('Content-Language', app()->getLocale());
        }

        return $response;
    }

    /**
     * @param  list<string>  $supported
     */
    private function fromHeader(Request $request, array $supported): ?string
    {
        foreach ($request->getLanguages() as $language) {
            if (in_array($language, $supported, true)) {
                return $language;
            }
        }

        return null;
    }
}
