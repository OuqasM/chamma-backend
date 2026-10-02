<?php

namespace App\Services;

use App\Models\Visit;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/**
 * Records one row per visitor, refreshed on every page view.
 *
 * Identity is a random token the server issues and the visitor's browser stores
 * in a cookie. It is deliberately *not* a MAC address — no web server can read
 * one, since the browser exposes no access to the network hardware layer — and
 * deliberately not a fingerprint of fonts, canvas or screen either, which would
 * track a person without their knowledge. A random cookie holds the same line
 * in the sand: it is first-party, inspectable and clearable by the visitor.
 *
 * What that buys, and what it costs:
 *
 * - Switching from mobile data to Wi-Fi does not create a second visitor, which
 *   an IP-keyed table could not achieve behind carrier NAT.
 * - Clearing cookies, a new browser, or incognito all look like a new visitor.
 * - Two people sharing one browser profile look like one visitor.
 *
 * The cookie is a 32-char hex string, which is 128 bits from a CSPRNG — the same
 * length as an MD5 digest, so the column is a fixed-width key rather than
 * free text, and a guessed value is not worth attempting.
 */
class VisitorTracker
{
    private const COOKIE = 'chamma_visitor';

    /**
     * How long the identity cookie lives. A year, so a shopper is not counted as
     * a new person every few weeks. It is first-party, so it needs no consent
     * banner in the EU/GDPR model where strictly-necessary cookies are exempt —
     * but that is a judgement for the store owner, not a code detail.
     */
    private const COOKIE_LIFETIME_DAYS = 365;

    /**
     * Bots and prefetchers would otherwise fill the table with rows nobody
     * ever saw. Checked against the user agent, which is all that is available
     * here.
     */
    private const IGNORED_AGENTS = [
        'bot', 'crawl', 'spider', 'slurp', 'curl', 'wget', 'python-requests',
        'headlesschrome', 'phantomjs', 'preview', 'facebookexternalhit',
        'lighthouse', 'pingdom', 'uptimerobot', 'ahrefs', 'semrush', 'mj12',
    ];

    /**
     * Records the visit and returns the identity cookie to attach to the
     * response, or null when the request was not counted.
     *
     * The cookie is returned rather than queued. Queueing relies on the
     * `AddQueuedCookiesToResponse` middleware being in the request's group, and
     * this service runs inside the `api` group, which does not include it — a
     * queued cookie there would be silently dropped and every visitor would look
     * brand new on each request. Handing the cookie back lets the caller attach
     * it to the response that is actually sent.
     */
    public function record(Request $request, string $locale, ?string $path = null): ?array
    {
        if ($this->shouldIgnore($request)) {
            return null;
        }

        $visitorId = $this->resolveVisitorId($request);
        $now = Carbon::now();

        $attributes = [
            'ip' => $request->ip(),
            'device' => $this->device($request),
            'browser' => $this->browser($request),
            'os' => $this->os($request),
            'path' => $this->truncate($path ?? $this->storefrontPath($request), 255),
            'referrer' => $this->truncate($request->headers->get('referer'), 255),
            'locale' => $locale,
            'last_seen' => $now,
        ];

        // Whether the row already existed decides the count, and it has to be
        // read before the write: `upsert` reports affected rows but not whether
        // it inserted or updated, so there is no way to ask afterwards.
        $isReturning = Visit::query()
            ->where('visitor_id', $visitorId)
            ->exists();

        // One statement, not a read-then-write pair.
        //
        // `firstOrCreate()` looks correct and is not: two parallel requests from
        // a first-time visitor both miss the lookup and both insert, so the
        // unique index on `visitor_id` then aborts the loser with an exception
        // instead of treating it as a second visit. `upsert` has no such window.
        //
        // `first_seen` is deliberately absent from the update list: it is the one
        // column that must not move on a return visit. Nor is `visits_count`,
        // which is incremented separately below so each parallel request adds
        // exactly one rather than writing a stale total over a newer one.
        Visit::query()->upsert(
            [$attributes + [
                'visitor_id' => $visitorId,
                'visits_count' => 1,
                'first_seen' => $now,
            ]],
            ['visitor_id'],
            ['ip', 'device', 'browser', 'os', 'path', 'referrer', 'locale', 'last_seen'],
        );

        if ($isReturning) {
            // An atomic `visits_count + 1` in the database, not a
            // read-modify-write in PHP, so nothing is lost under concurrency.
            Visit::query()
                ->where('visitor_id', $visitorId)
                ->increment('visits_count');
        }

        $visit = Visit::query()->where('visitor_id', $visitorId)->first();

        if ($visit === null) {
            // `upsert` reported success but there is no row, which means the table
            // was altered underneath us. Nothing sensible to return.
            return null;
        }

        return [
            'visit' => $visit,
            'cookie' => $this->identityCookie($visitorId, $request),
        ];
    }

    /**
     * Reads the identity from the cookie, or mints one.
     *
     * A malformed or absent cookie gets a fresh token rather than being
     * rejected: a client with a stale cookie from an older release should still
     * be counted, just as a new visitor.
     */
    private function resolveVisitorId(Request $request): string
    {
        $existing = $request->cookie(self::COOKIE);

        if (is_string($existing) && preg_match('/^[a-f0-9]{32}$/', $existing) === 1) {
            return $existing;
        }

        // 16 bytes of CSPRNG output rendered as hex gives the documented
        // 32-character lowercase token. `Str::random()` would not: it draws
        // from the full alphanumeric alphabet, so most tokens it produced would
        // fail the check above on the very next request and be replaced again,
        // making the same visitor look new every time.
        return bin2hex(random_bytes(16));
    }

    /**
     * The identity cookie for this request.
     *
     * HttpOnly: nothing in the browser ever needs to read this value — the
     * storefront never touches it and the admin panel reads the database — so
     * script access would only give XSS a stable handle on the visitor. Secure
     * is derived from the request instead of hardcoded, because a hardcoded
     * `true` means the browser silently drops the cookie on plain HTTP, which
     * is how the API is reached in local development.
     *
     * SameSite=Lax: the storefront and API are same-site, and Lax is enough for
     * a first-party identity while still blocking it on cross-site subrequests.
     */
    private function identityCookie(string $visitorId, Request $request): SymfonyCookie
    {
        // Named arguments, not positional. The signature is
        // `($name, $value, $minutes, $path, $domain, $secure, $httpOnly, $raw,
        // $sameSite)` — note that `$secure` sits *before* `$httpOnly` and `$raw`
        // sits between `$httpOnly` and `$sameSite`. A positional call that looks
        // right silently writes the flags into the wrong slots, and a boolean
        // `sameSite` throws from Symfony's validator.
        return Cookie::make(
            name: self::COOKIE,
            value: $visitorId,
            minutes: self::COOKIE_LIFETIME_DAYS * 24 * 60,
            path: '/',
            secure: $request->isSecure(),
            httpOnly: true,
            // 'lax' as the string Symfony validates, not the boolean true.
            sameSite: 'lax',
        );
    }

    /**
     * The storefront path behind an API request.
     *
     * The tracker runs inside the `api` group, so `Request::path()` returns the
     * *endpoint* that was called — `/api/fr/offers` — not the page the shopper
     * is looking at. Recording that verbatim would fill the admin panel with a
     * dozen identical API routes and hide which page is actually popular. The
     * API mirrors the storefront one-to-one, so the page path is recoverable by
     * dropping the `/api` prefix and the locale segment.
     */
    private function storefrontPath(Request $request): string
    {
        $segments = array_values(array_filter(
            explode('/', $request->path()),
            static fn ($segment) => $segment !== '',
        ));

        array_shift($segments); // the literal "api" prefix

        // The next segment is the locale on the locale-scoped routes. It is
        // dropped too: the storefront serves the same page at /fr/… and /ar/…,
        // and the panel stores one row per visitor, so a locale-specific path
        // would split a single visitor's history across languages.
        if (($segments[0] ?? null) === $request->attributes->get('locale')) {
            array_shift($segments);
        }

        $path = implode('/', $segments);

        return $path === '' ? '/' : $path;
    }

    private function shouldIgnore(Request $request): bool
    {
        $agent = strtolower((string) $request->userAgent());

        if ($agent === '') {
            return true;
        }

        foreach (self::IGNORED_AGENTS as $needle) {
            if (str_contains($agent, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Three classes only, because that is what the panel filters on and because
     * the user agent cannot be trusted to be anything more precise: it is
     * self-reported and trivially spoofed. A handheld that does not match the
     * mobile patterns is `other`, never `desktop` by default.
     */
    private function device(Request $request): string
    {
        $agent = (string) $request->userAgent();

        if (preg_match('/iPad|Tablet|PlayBook|Silk|Android(?!.*Mobile)/i', $agent)) {
            return 'tablet';
        }

        if (preg_match('/Mobi|iPhone|iPod|Android|Windows Phone|BlackBerry|Opera Mini|IEMobile/i', $agent)) {
            return 'mobile';
        }

        if (preg_match('/Mozilla/i', $agent)) {
            return 'desktop';
        }

        return 'other';
    }

    /**
     * Best-effort browser and OS names, ordered so the more specific patterns
     * are tested first — Chrome, Edge and Opera all claim to be Safari, and
     * Firefox's token contains no "Chrome" at all.
     */
    private function browser(Request $request): ?string
    {
        return $this->match($request, [
            'Edge' => '/Edg[eA]?/',
            'Opera' => '/OPR\/|Opera/',
            'Samsung Internet' => '/SamsungBrowser/',
            'Chrome' => '/CriOS|Chrome/',
            'Firefox' => '/Firefox|FxiOS/',
            'Safari' => '/Safari/',
            'Internet Explorer' => '/MSIE|Trident/',
        ]);
    }

    private function os(Request $request): ?string
    {
        return $this->match($request, [
            'Windows' => '/Windows/',
            'iOS' => '/iPhone|iPad|iPod/',
            'Android' => '/Android/',
            'macOS' => '/Mac OS X|Macintosh/',
            'Chrome OS' => '/CrOS/',
            'Linux' => '/Linux|X11/',
        ]);
    }

    private function match(Request $request, array $patterns): ?string
    {
        $agent = (string) $request->userAgent();

        foreach ($patterns as $name => $pattern) {
            if (preg_match($pattern, $agent) === 1) {
                return $name;
            }
        }

        return null;
    }

    private function truncate(?string $value, int $length): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Str::limit($value, $length, '');
    }
}
