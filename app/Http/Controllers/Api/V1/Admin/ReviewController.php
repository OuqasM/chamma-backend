<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReviewRequest;
use App\Models\ReviewScreenshot;
use App\Services\ImageLibrary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The customer reviews: WhatsApp conversation screenshots the storefront shows
 * on its reviews page and prompts visitors with.
 *
 * No storefront cache flush wiring, on purpose: the public reviews endpoint is
 * a plain indexed read that is never cached, so an admin write here has
 * nothing to invalidate.
 */
class ReviewController extends Controller
{
    public function __construct(private readonly ImageLibrary $images) {}

    public function index(): AnonymousResourceCollection
    {
        return AdminReviewResource::collection(
            ReviewScreenshot::query()->ordered()->get()
        );
    }

    public function store(ReviewRequest $request): JsonResponse
    {
        $data = $request->safe()->only(['image', 'position', 'is_active']);

        // Coerce a submitted checkbox, but let the column default apply
        // when the admin says nothing, so nothing is silently hidden.
        if ($request->exists('is_active') && $request->input('is_active') !== null) {
            $data['is_active'] = $request->boolean('is_active');
        } else {
            unset($data['is_active']);
        }

        $review = ReviewScreenshot::create($data);

        // Refresh so column defaults that were not submitted are reflected.
        $review->refresh();

        return response()->json(['review' => new AdminReviewResource($review)], 201);
    }

    public function update(ReviewRequest $request, ReviewScreenshot $review): JsonResponse
    {
        $data = $request->safe()->only(['image', 'position', 'is_active']);

        // Coerce a submitted checkbox, but let an untouched one keep the row's
        // current state rather than writing null into a NOT NULL column.
        if ($request->exists('is_active') && $request->input('is_active') !== null) {
            $data['is_active'] = $request->boolean('is_active');
        } else {
            unset($data['is_active']);
        }

        // Swapping the screenshot deletes the one it replaces, so the media
        // library does not accumulate orphaned files behind live rows.
        if (isset($data['image']) && $data['image'] !== $review->image) {
            $this->images->delete($review->image);
        }

        $review->update($data);

        return response()->json(['review' => new AdminReviewResource($review->fresh())]);
    }

    public function destroy(ReviewScreenshot $review): JsonResponse
    {
        $this->images->delete($review->image);
        $review->delete();

        return response()->json(['message' => 'deleted']);
    }
}
