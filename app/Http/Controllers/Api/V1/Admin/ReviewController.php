<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReviewRequest;
use App\Models\ReviewScreenshot;
use App\Services\ImageLibrary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

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

    /**
     * Add a whole selection of screenshots at once.
     *
     * A review row holds one screenshot, so "upload several" means "add one
     * row each". The upload itself is still a call per file — the upload
     * endpoint owns validation and optimisation — but the rows are written in
     * one transaction: a batch that fails halfway would leave the owner unable
     * to tell which screenshots made it and which did not.
     *
     * A position, when given, numbers the rows from there in selection order;
     * left blank, every row takes the column default and the list appends them
     * in creation order, exactly like the single-row form.
     */
    public function storeMany(Request $request): JsonResponse
    {
        $data = $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:50'],
            'images.*' => ['required', 'string', 'max:255'],
            'position' => ['nullable', 'integer', 'min:0', 'max:999'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $reviews = DB::transaction(function () use ($data) {
            $base = $data['position'] ?? null;

            return collect($data['images'])
                ->values()
                ->map(function (string $image, int $index) use ($data, $base) {
                    $attributes = ['image' => $image];

                    if ($base !== null) {
                        $attributes['position'] = $base + $index;
                    }

                    if (array_key_exists('is_active', $data) && $data['is_active'] !== null) {
                        $attributes['is_active'] = (bool) $data['is_active'];
                    }

                    return ReviewScreenshot::create($attributes);
                });
        });

        return response()->json([
            'reviews' => AdminReviewResource::collection($reviews),
        ], 201);
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
