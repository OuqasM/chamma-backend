<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class OrderBenefitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    /**
     * The owner's own figure for what the order earned.
     *
     * It replaces the calculation, so it stands whatever the arithmetic says —
     * a loss is a loss, and a negative figure is a fact rather than a mistake.
     * Leaving it empty takes a set figure away, which gives the calculation
     * back.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'benefit' => ['nullable', 'numeric', 'min:-9999999', 'max:9999999'],
        ];
    }

    /**
     * The figure, or its absence when the owner takes their correction back.
     */
    public function figure(): ?float
    {
        $value = $this->input('benefit');

        return $value === null ? null : (float) $value;
    }
}
