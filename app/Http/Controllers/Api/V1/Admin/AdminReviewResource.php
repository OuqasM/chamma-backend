<?php

namespace App\Http\Controllers\Api\V1\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Admin view of a review screenshot.
 *
 * Same reasoning as AdminBrandResource: the storefront resource resolves the
 * media URL, while the edit form must round-trip the stored path — so both
 * travel, under different keys.
 */
class AdminReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'image' => $this->image
                ? Storage::disk(config('chamma.disk'))->url($this->image)
                : null,
            // The raw column value, for the edit form.
            'image_path' => $this->image,
            'position' => (int) $this->position,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
