<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Moroccan mobile numbers: 06/07 + 8 digits, +212, or 00212.
 * Landlines (05) are accepted too, because some customers order on one.
 * Spaces, dots, dashes and parentheses are tolerated — people type them.
 */
class MoroccanPhone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) && ! is_int($value)) {
            $fail(__('api.validation.phone'))->translate();

            return;
        }

        $digits = preg_replace('/[\s.\-()]/', '', (string) $value) ?? '';

        if (! preg_match('/^(?:\+?212|00212|0)[5-7]\d{8}$/', $digits)) {
            $fail(__('api.validation.phone'))->translate();
        }
    }
}
