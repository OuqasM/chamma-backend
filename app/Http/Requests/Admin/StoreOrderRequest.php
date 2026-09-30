<?php

namespace App\Http\Requests\Admin;

use App\Services\PaymentService;
use App\Services\ShippingZoneService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\Rule;

/**
 * An order the owner writes in by hand.
 *
 * The shape matches the storefront checkout deliberately. Same fields, same
 * cities, same payment codes, so a phone order lands in the same table with the
 * same column meanings as a website order and needs no second code path to read
 * it back. The rules come from the services rather than being spelled out here,
 * which is what keeps "Casablanca" from being accepted by checkout and
 * rejected by the panel.
 *
 * What is deliberately absent is `authorize()` on the storefront's terms. This
 * is the owner recording a conversation they just had, so the only questions
 * worth asking are whether the fields make sense.
 */
class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authenticated and `admin` already: the route group carries both.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // "Ahmed Ben Ali", not an email account name. Split into first/last
            // columns by OrderService, exactly as a website order is.
            'name' => ['required', 'string', 'min:2', 'max:120'],

            // MoroccanPhone is the storefront's rule, and it exists to catch a
            // customer mistyping a number they expect to be called back on. Here
            // the owner is transcribing what someone said on the phone; if they
            // write "+212 6 12 34 56 78" that is the number to dial, and second
            // guessing it would fail an order that is genuinely deliverable.
            'phone' => ['required', 'string', 'max:30'],

            'email' => ['nullable', 'email', 'max:120'],
            'city' => ['required', 'string', Rule::in(app(ShippingZoneService::class)->names())],
            'address' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'payment_method' => [
                'required',
                'string',
                Rule::in(array_column(app(PaymentService::class)->methods($this->localeOrDefault()), 'code')),
            ],

            // Cash in hand, or a transfer already cleared, is the usual reason
            // for typing an order in rather than waiting for the website.
            // Anything else is left to the existing status flow.
            'payment_status' => ['nullable', Rule::in(['pending', 'paid'])],

            // The panel is French, Arabic and English, so which language the
            // customer used is a real question here — it decides the language
            // the order is written and the product names are snapshotted in.
            'locale' => ['nullable', 'string', Rule::in(array_keys(config('chamma.locales', [])))],

            // Upper bound is a stock-take bound: past a hundred of one item the
            // order is a wholesale arrangement, and the owner should be talking
            // to the customer about it rather than typing it here.
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'city.in' => __('api.validation.city_unknown'),
            'payment_method.in' => __('api.validation.payment_method_unknown'),
            'items.required' => __('api.validation.items_required'),
            'items.min' => __('api.validation.items_required'),
            'items.*.product_id.required' => __('api.validation.product_required'),
            'items.*.product_id.exists' => __('api.validation.product_unknown'),
            'items.*.quantity.required' => __('api.validation.quantity_required'),
        ];
    }

    /**
     * The language the order is written in, for product-name snapshots.
     *
     * Falls back to the language the panel itself is open in, which is the best
     * available guess about the customer when the owner did not pick one.
     */
    private function localeOrDefault(): string
    {
        $locale = $this->input('locale');

        return is_string($locale) && $locale !== ''
            ? $locale
            : (string) App::getLocale();
    }
}
