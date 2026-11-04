<?php

namespace App\Http\Requests\Admin;

use App\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrderStatusRequest extends FormRequest
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
        return [
            'status' => ['required', 'string', Rule::in(OrderStatus::values())],

            // The owner's own figure for what the order earned, given as the
            // order is handed over. Empty, the benefit is worked out when the
            // sale happens; typed in, it is the figure the order carries.
            'benefit' => ['nullable', 'numeric', 'min:-9999999', 'max:9999999'],
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
