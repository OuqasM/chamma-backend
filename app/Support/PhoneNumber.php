<?php

namespace App\Support;

/**
 * Moroccan phone numbers in the one form the database and wa.me agree on.
 *
 * People type "0612345678", "06 12 34 56 78" and "00212612345678" for the same
 * handset. Storing whichever arrived would make the waiting list's unique index
 * treat one person as three, so everything is reduced to `+212` plus the
 * national digits before it is stored or compared.
 */
final class PhoneNumber
{
    /**
     * `+212612345678`, or null when the input is not a Moroccan number.
     *
     * The `00212` prefix is international access code, so it is replaced rather
     * than stripped: `00212612345678` is +212 then 612345678, and dropping the
     * 00212 leaves a number with the country code missing.
     */
    public static function normalise(string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '00212')) {
            $national = substr($digits, 5);
        } elseif (str_starts_with($digits, '212')) {
            $national = substr($digits, 3);
        } elseif (str_starts_with($digits, '0')) {
            $national = substr($digits, 1);
        } else {
            return null;
        }

        // 5-7 prefix then eight digits, the same shape `MoroccanPhone` allows.
        if (! preg_match('/^[5-7]\d{8}$/', $national)) {
            return null;
        }

        return '+212'.$national;
    }

    /**
     * Digits only, for a wa.me link. wa.me rejects the leading plus and the
     * separators, and a national number's leading zero would address a
     * different number entirely.
     */
    public static function whatsappDigits(string $value): string
    {
        $normalised = self::normalise($value);

        return $normalised ? (string) preg_replace('/\D+/', '', $normalised) : '';
    }
}
