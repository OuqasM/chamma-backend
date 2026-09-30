<?php

namespace App\Http\Requests;

use App\Rules\MoroccanPhone;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A customer signing up to hear about a product coming back.
 *
 * A name and a phone, and nothing else. The number is what the owner dials and
 * the name is what they say when it answers; an email would suggest a written
 * notification that nothing sends, and collecting more than the list needs
 * would be personal data held for no reason.
 */
class StoreWaitlistRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The page being unavailable is the authorisation: a shopper looking at
        // a sold-out product has standing to ask to be told, and they are
        // identified by nothing.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // No locale rule: the language comes from the URL prefix, which
        // SetLocale has already validated and applied. A body field for it
        // would be a second, spoofable source for the same fact.
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', new MoroccanPhone],
        ];
    }

    protected function prepareForValidation(): void
    {
        // A stray space is not a name, and " " would otherwise satisfy a
        // length check while showing the owner a blank column.
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'phone' => trim((string) $this->input('phone')),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $name = __('api.validation.attributes.customer_name');

        return [
            'name.required' => __('api.validation.required', ['attribute' => $name]),
            'name.min' => __('api.validation.min.string', ['attribute' => $name, 'min' => 2]),
            'phone.required' => __('api.validation.phone_required'),
            'phone.*' => __('api.validation.phone'),
        ];
    }
}
