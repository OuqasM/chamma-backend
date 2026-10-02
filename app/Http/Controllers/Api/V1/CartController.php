<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SyncCartRequest;
use App\Services\CartService;
use App\Services\VisitorTracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The one endpoint that makes an abandoned cart visible.
 *
 * The cart the shopper is looking at lives in their browser's `localStorage`,
 * so until they check out it exists nowhere the shop can see. This writes it
 * server-side, keyed on the same first-party identity cookie the visitor
 * tracker already issues, which is what turns "someone browsed" into "someone
 * left three things in a basket".
 */
class CartController extends Controller
{
    public function __construct(
        private readonly CartService $carts,
        private readonly VisitorTracker $tracker,
    ) {}

    /**
     * Replaces the recorded cart with the contents the browser is holding.
     *
     * Sent on every add, remove and quantity change, debounced on the client.
     * It is not on the `track` middleware — that is for page views, and this is a
     * POST that fires several times during one visit — so the identity is
     * resolved here instead and the cookie attached by hand.
     *
     * Returns 204: the browser already has the cart, it is `localStorage` that
     * the shopper's interface reads, and echoing a copy back would be a second
     * source of truth for the same object. The tests read the database instead.
     */
    public function store(SyncCartRequest $request): JsonResponse
    {
        $visitorId = $this->tracker->identity($request);

        // The locale is resolved from the route segment by SetLocale, so this is
        // the language the shopper is actually reading rather than whatever the
        // request body claimed.
        $locale = $request->attributes->get('locale') ?: app()->getLocale();

        try {
            $this->carts->sync($visitorId, $request->validated()['items'] ?? [], $locale);
        } catch (\Throwable $e) {
            // Swallowed on purpose. The cart is already in the shopper's
            // `localStorage` and their basket works; a failed record must never
            // surface as an error on a page, or turn a quantity change into a
            // failed request. Losing the row costs the owner a report line.
            Log::warning('cart_sync_failed', ['message' => $e->getMessage()]);

            return response()->json(null, 204);
        }

        $response = response()->json(null, 204);

        // Attached here rather than queued inside the service: the `api`
        // middleware group has no `AddQueuedCookiesToResponse`, so a queued
        // cookie would never reach the client. The id is passed back in rather
        // than re-resolved, so the token handed to the browser is the same one
        // this cart was just written under.
        $response->headers->setCookie(
            $this->tracker->identityCookieFor($visitorId, $request)
        );

        return $response;
    }
}
