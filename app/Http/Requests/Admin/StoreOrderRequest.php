<?php

namespace App\Http\Requests\Admin;

use App\Concerns\ValidatesOrderVariants;
use App\Rules\MoroccanPhone;
use App\Services\PaymentService;
use App\Services\ShippingZoneService;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
    use ValidatesOrderVariants;

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

            // The number is normalised before it is validated (see
            // `prepareForValidation`), so what reaches this rule is the one
            // form the shop agrees on: `+212` and nine national digits.
            //
            // This used to be a free string of up to thirty characters,
            // because the owner is transcribing what someone said on the phone
            // and second-guessing it would fail a deliverable order. That was
            // right while the number was only a thing to call back on. It is
            // not right any more: the number is how a customer is found again,
            // and a number that cannot be normalised would file an order under
            // a customer nobody will ever find.
            'phone' => ['required', 'string', 'max:30', new MoroccanPhone],

            'city' => ['required', 'string', Rule::in(app(ShippingZoneService::class)->names())],
            'address' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],

            // What this order costs to deliver. The panel starts from the
            // carrier's price for the city — that is what the order is
            // normally charged — and the owner decides what this one costs:
            // a negotiated rate, or a free delivery.
            //
            // The bound is there so a mistyped figure cannot turn a
            // 35-dirham delivery into a 35 000-dirham one.
            'shipping_cost' => ['nullable', 'numeric', 'min:0', 'max:1000'],

            // Whether the figure is **kept for that city**. A price the owner
            // sets for Casablanca is the price of delivering to Casablanca,
            // and the next order there is billed the same way. A free
            // delivery for one order is not a decision about the whole city,
            // so the panel says which of the two it means.
            'remember_fee' => ['nullable', 'boolean'],

            // The owner's own figure for what the order earned, when the
            // sums do not know what they know: the rate the supplier actually
            // gave, the courier that charged more than the shop billed.
            //
            // Left empty, the benefit is worked out — the total minus the
            // goods minus the parcel — and keeps following the purchase
            // prices when they are rectified. Typed in, it is the figure.
            'benefit' => ['nullable', 'numeric', 'min:-9999999', 'max:9999999'],

            // The stock decisions the owner made while writing the order: this
            // bottle has just sold out, or this size is discontinued for good.
            //
            // They travel with the order and are applied **when it is saved**,
            // not when they are clicked: an order the owner abandons must not
            // leave the shop's stock changed.
            'stock_actions' => ['nullable', 'array', 'max:20'],
            'stock_actions.*.type' => ['required', 'string', 'in:out_of_stock,remove_variant'],
            'stock_actions.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'stock_actions.*.variant' => ['nullable', 'string', 'max:120'],
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
            'items.*.variant' => ['nullable', 'string', 'max:120'],
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
            // One message for every way the number can be wrong: it is the one
            // sentence that says what a usable Moroccan number looks like.
            'phone.*' => __('api.validation.phone'),
            'items.required' => __('api.validation.items_required'),
            'items.min' => __('api.validation.items_required'),
            'items.*.product_id.required' => __('api.validation.product_required'),
            'items.*.product_id.exists' => __('api.validation.product_unknown'),
            'items.*.quantity.required' => __('api.validation.quantity_required'),
        ];
    }

    /**
     * The number is reduced to the one form the shop agrees on before anything
     * else looks at it.
     *
     * "0612345678", "06 12 34 56 78", "+212 6 12 34 56 78" and
     * "00212612345678" are one handset, and the customer record is keyed by
     * that handset. Normalising here — rather than in the service that reads
     * it — means the order, the customer and the panel all speak about the
     * same number, and a number that is not Moroccan is refused with one
     * clear sentence instead of being filed under a shape nobody can search.
     */
    protected function prepareForValidation(): void
    {
        $phone = trim((string) $this->input('phone'));

        $this->merge([
            'name' => trim((string) $this->input('name')),
            'city' => trim((string) $this->input('city')),
            'address' => trim((string) $this->input('address')),
            'notes' => $this->input('notes') !== null
                ? trim((string) $this->input('notes'))
                : null,
            // Left as typed when it cannot be normalised, so the rule below
            // can say why it was refused.
            'phone' => PhoneNumber::normalise($phone) ?? $phone,
        ]);
    }

    /**
     * Same variant rules the storefront checkout answers to: a product sold in
     * sizes is ordered in one of them, whatever way the order reached here.
     */
    public function withValidator(Validator $validator): void
    {
        $this->validateVariantLines($validator);
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
