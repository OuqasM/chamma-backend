<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // A new review must carry a screenshot; an edit may change only the
        // order or the switch and leave the screenshot exactly as it is.
        $image = $this->route('review')
            ? ['nullable', 'string', 'max:255']
            : ['required', 'string', 'max:255'];

        return [
            // The path an upload returned, not a file: the panel uploads the
            // screenshot to POST /api/admin/uploads first and hands the path
            // here. Same two-step as the product photos, so the optimizer and
            // the 4 MB guard stay in one place.
            'image' => $image,
            'position' => ['sometimes', 'integer', 'min:0', 'max:999'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('api.validation.attributes');
    }
}
