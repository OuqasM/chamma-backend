<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWaitlistRequest;
use App\Models\Product;
use App\Services\WaitlistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\App;

/**
 * Signup for the "tell me when it's back" list on an unavailable product.
 *
 * Public, like checkout: the customer is identified by nothing but the phone
 * number they type. The product is resolved by slug in the requested locale,
 * the same way the product page resolves it, so a signed-up entry always
 * belongs to the page the customer was actually looking at.
 */
class WaitlistController extends Controller
{
    public function __construct(private readonly WaitlistService $waitlist) {}

    public function store(StoreWaitlistRequest $request, string $slug): JsonResponse
    {
        $locale = App::getLocale();

        $product = Product::query()
            ->active()
            ->whereSlug($slug, $locale)
            ->firstOrFail();

        // The form only exists on a page that says "not available". A signup
        // arriving for something that is already on sale means a stale page or
        // a scripted caller, and either way the customer would be promised a
        // notification about a product they can already buy.
        //
        // 404 rather than 422: from the caller's side this is not a product
        // they can wait for, and the panel has no such entry to show them.
        if ($product->in_stock) {
            abort(404);
        }

        // The locale is the URL prefix the customer was browsing under, which
        // SetLocale has already resolved onto the app. Reading it from the
        // request body instead would let a caller label the entry with a
        // language the page was never in.
        $result = $this->waitlist->add($product, $request, $locale);

        return response()->json([
            'waitlist' => [
                'id' => $result['entry']->id,
                'product_id' => $product->id,
                // Whether this row is new. Both cases answer the same way on
                // purpose: a returning customer is told the same thing, because
                // a different message for the same outcome would tell them
                // something had gone wrong.
                'created' => $result['created'],
            ],
        ], $result['created'] ? 201 : 200);
    }
}
