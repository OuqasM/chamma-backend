<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReviewResource;
use App\Models\ReviewScreenshot;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The customer reviews the storefront shows: WhatsApp conversation
 * screenshots, in the order the owner set.
 *
 * The route is locale prefixed like every storefront route, but the payload is
 * language-free — a screenshot is the same screenshot in French, Arabic and
 * English — so the prefix carries no per-locale work. It stays on `track`,
 * because the reviews page is a page a visitor can land on.
 *
 * Not cached server-side: the table holds a handful of rows behind one indexed
 * query, so a cache (and the flush wiring it would demand from every admin
 * write) would cost more bookkeeping than it could ever save.
 */
class ReviewController extends Controller
{
    public function __invoke(): AnonymousResourceCollection
    {
        return ReviewResource::collection(
            ReviewScreenshot::query()->active()->ordered()->get()
        );
    }
}
