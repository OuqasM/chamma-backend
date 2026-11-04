<?php

namespace App\Support;

/**
 * Moroccan phone numbers in the one form the shop writes them.
 *
 * People type "0612345678", "06 12 34 56 78" and "00212612345678" for the same
 * handset. Storing whichever arrived would make the waiting list's unique index
 * treat one person as three, so everything is reduced to the national form
 * before it is stored or compared.
 *
 * **The national form is ten characters: "0612345678".** It is how the number
 * is written in Morocco, how the owner reads it off a card, and how it fits in
 * a column without being cut short. The country code is not part of it — a
 * number that starts "+212" is the same number, and the owner is not going to
 * dial the country code to reach somebody in the next town.
 *
 * The national digits are also what the panel searches with: the shop's
 * customers are found by the number they gave, and the number is typed from
 * the left, so a partial one is a prefix and not a whole number.
 */
final class PhoneNumber
{
    /**
     * `0612345678`, or null when the input is not a Moroccan number.
     *
     * The `00212` prefix is international access code, so it is replaced rather
     * than stripped: `00212612345678` is +212 then 612345678, and dropping the
     * 00212 leaves a number with the country code missing.
     */
    public static function normalise(string $value): ?string
    {
        $national = self::nationalDigits($value);

        if ($national === null || strlen($national) !== 9) {
            return null;
        }

        // The leading zero is what makes it ten characters and what makes it
        // a national number rather than a fragment of one.
        return '0'.$national;
    }

    /**
     * The nine national digits of what was typed: "612" for "0612", "612345678"
     * for "+212 6 12 34 56 78".
     *
     * A partial number is allowed — this is not a validity check, it is the
     * shape a number has while somebody is still typing it. `normalise()` is
     * what decides whether the number is complete.
     *
     * The leading zero is optional: "612345678" is the same handset as
     * "0612345678", and a number read off a card or copied from a message
     * often arrives without it.
     */
    public static function nationalDigits(string $value): ?string
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
            $national = $digits;
        }

        // 5-7 prefix then up to eight more digits, the same shape
        // `MoroccanPhone` allows, short of the completeness check.
        if (! preg_match('/^[5-7]\d{0,8}$/', $national)) {
            return null;
        }

        return $national;
    }

    /**
     * The digit strings a term could mean.
     *
     * A search is a **fragment**, not a number: the owner remembers "45678"
     * or "2345" more often than the whole thing, and the middle of a number
     * is the part they are surest of. Both the digits as typed and the digits
     * with the country code — or the leading zero — taken off are worth
     * looking for, because the number is stored one way and typed another.
     *
     * @return array<int, string>
     */
    public static function searchDigits(string $value): array
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if ($digits === '') {
            return [];
        }

        $candidates = [$digits];

        // The country code, with and without its international access prefix.
        foreach (['00212', '212'] as $prefix) {
            if (str_starts_with($digits, $prefix) && strlen($digits) > strlen($prefix)) {
                $candidates[] = substr($digits, strlen($prefix));
            }
        }

        // And the national number without its leading zero, which is how the
        // number is stored.
        if (str_starts_with($digits, '0') && strlen($digits) > 1) {
            $candidates[] = substr($digits, 1);
        }

        return array_values(array_unique(array_filter(
            $candidates,
            fn (string $candidate) => $candidate !== '',
        )));
    }

    /**
     * Digits only, for a wa.me link.
     *
     * wa.me wants the country code and no national leading zero: "212" then
     * "612345678". The leading zero would address a different number
     * entirely, and the "+" is rejected outright.
     */
    public static function whatsappDigits(string $value): string
    {
        $normalised = self::normalise($value);

        if ($normalised === null) {
            return '';
        }

        return '212'.substr($normalised, 1);
    }
}
