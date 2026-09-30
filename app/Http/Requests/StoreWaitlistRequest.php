<?php

namespace App\Http\Requests;

use App\Rules\MoroccanPhone;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A customer signing up to hear about a product coming back.
 *
 * The phone is the only field, because it is the only one the owner can act
 * on. No name and no email: an email column would suggest a written
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
            'phone' => ['required', 'string', new MoroccanPhone],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.required' => __('api.validation.phone_required'),
            'phone.*' => __('api.validation.phone'),
        ];
    }
}
