<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * A customer review for the storefront: the screenshot and nothing else.
 *
 * The payload is deliberately bare — an id to key the carousel by, the image
 * URL, and the position the owner gave it. A review is a conversation the
 * customer already had, so there is no copy to translate and no metadata for
 * the page to read.
 */
class ReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'image' => $this->image
                ? Storage::disk(config('chamma.disk'))->url($this->image)
                : null,
            'position' => (int) $this->position,
        ];
    }
}
