<?php

namespace App\Http\Requests;

use App\Services\CartService;
use Illuminate\Foundation\Http\FormRequest;

class SyncCartRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Guest checkout is the storefront default and the cart needs no more
        // authorisation than that: a cart is a list of the shopper's own
        // intentions, addressed to nobody.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // An empty array is valid and meaningful: it records the cart being
            // emptied, which is the state most likely to be followed by an
            // abandoned row otherwise. Refusing it would mean a shopper who
            // clears their cart leaves the previous contents standing forever in
            // the report.
            'items' => ['present', 'array', 'max:'.CartService::MAX_LINES],
            'items.*.product_id' => ['required', 'integer', 'min:1'],
            // No `max` here on purpose. The service clamps to
            // CartService::MAX_QUANTITY and that is the only place the limit is
            // enforced, because it is the boundary that has to hold no matter who
            // calls it. A `max` rule would reject the payload with a 422 instead,
            // leaving the service clamp unreachable and giving the cap two owners
            // that could disagree after a later edit.
            'items.*.quantity' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.max' => __('api.errors.cart_too_large'),
        ];
    }
}
