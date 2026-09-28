<?php

namespace App\Http\Requests;

use App\Rules\MoroccanPhone;
use App\Services\ShippingZoneService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCheckoutRequest extends FormRequest
{
    public function __construct(
        private readonly ShippingZoneService $zones,
    ) {}

    public function authorize(): bool
    {
        // Guest checkout is the default flow: the cart alone authorises it.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxQuantity = 20;

        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', new MoroccanPhone],
            // The city must be one the carrier delivers to: an order we cannot
            // ship is worse than a rejected checkout.
            'city' => ['required', 'string', Rule::in($this->zones->names())],
            'address' => ['required', 'string', 'min:8', 'max:250'],
            'notes' => ['nullable', 'string', 'max:500'],

            'payment_method' => ['nullable', 'string', Rule::in(config('chamma.payment.methods', []))],
            'locale' => ['nullable', 'string', 'in:'.implode(',', array_keys(config('chamma.locales')))],

            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:'.$maxQuantity],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        // `name` is shared with the catalogue ("Nom" of a product), so the
        // customer's own field is labelled explicitly here.
        $name = __('api.validation.attributes.customer_name');

        return [
            'name.required' => __('api.validation.required', ['attribute' => $name]),
            'name.min' => __('api.validation.min.string', ['attribute' => $name, 'min' => 2]),
            'city.in' => __('api.errors.city_not_deliverable'),
            'items.required' => __('api.validation.required', ['attribute' => __('api.validation.attributes.items')]),
            'items.min' => __('api.validation.min.array', ['attribute' => __('api.validation.attributes.items'), 'min' => 1]),
            'payment_method.in' => __('api.errors.payment_method_unavailable'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('api.validation.attributes');
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // Reject duplicate lines so totals stay unambiguous.
            $ids = collect($this->input('items', []))->pluck('product_id')->filter();

            if ($ids->duplicates()->isNotEmpty()) {
                $validator->errors()->add('items', __('api.validation.attributes.items'));
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $name = trim((string) $this->input('name'));
        $city = trim((string) $this->input('city'));

        $this->merge([
            'name' => $name,
            'address' => trim((string) $this->input('address')),
            'notes' => trim((string) $this->input('notes')),
            'phone' => trim((string) $this->input('phone')),
            // "casablanca" and "Casablanca " are the same delivery zone, so the
            // city is stored the way the carrier writes it and the `in:` rule
            // only ever sees a name the store really ships to.
            'city' => $this->zones->canonical($city) ?? $city,
        ]);
    }
}
