<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\NavigationService;
use Illuminate\Http\JsonResponse;

/**
 * Bootstrap payload: everything the shell needs on first paint (navigation,
 * footer) in a single request, already translated.
 *
 * The lists are read by `NavigationService` rather than through the SEO
 * resources, because this endpoint is a menu and not an index — see the service
 * for what that leaves out and why.
 *
 * Deliberately **not** on the `track` middleware. It is not a page anyone can
 * land on: the shell fetches it on every cold load and every language change, so
 * counting it recorded a `navigation` row against every visitor on every page and
 * inflated both the visitor count and the path breakdown. The page it accompanies
 * is tracked on its own route.
 */
class NavigationController extends Controller
{
    public function __construct(private readonly NavigationService $navigation) {}

    public function __invoke(): JsonResponse
    {
        return response()
            ->json($this->navigation->payload(app()->getLocale()))
            // The payload only changes when the owner edits a brand or a category,
            // and the service invalidates it there. This is here so a shared cache
            // in front of the app does not serve a stale menu after that.
            ->header('Cache-Control', 'public, max-age=300');
    }
}
