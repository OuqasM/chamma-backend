<?php

namespace App\Http\Middleware;

use App\Services\VisitorTracker;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Counts a storefront page view against a visitor.
 *
 * Runs after the response has been produced, so a failure here can never turn a
 * working page into an error: a shopper seeing the catalogue matters more than
 * their visit being recorded. It also means the identity cookie is queued onto
 * the response that is actually sent.
 *
 * Applied to the storefront GET routes only. Admin and auth routes are excluded
 * on purpose, so an admin browsing the back office does not appear in the
 * visitor list, and login attempts are not recorded as visits.
 */
class TrackVisitor
{
    public function __construct(private readonly VisitorTracker $tracker) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            // The locale is already resolved by SetLocale, which runs earlier in
            // the stack, and reflects the route segment rather than a guess.
            $recorded = $this->tracker->record(
                $request,
                $request->attributes->get('locale') ?: app()->getLocale(),
            );

            if ($recorded !== null) {
                // Attached here rather than queued inside the service: the `api`
                // middleware group has no `AddQueuedCookiesToResponse`, so a
                // queued cookie would never reach the client.
                $response->headers->setCookie($recorded['cookie']);
            }
        } catch (\Throwable $e) {
            // Deliberately swallowed and reported rather than rethrown.
            report($e);
        }

        return $response;
    }
}
