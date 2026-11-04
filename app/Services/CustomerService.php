<?php

namespace App\Services;

use App\Models\Customer;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

/**
 * The shop's customers, and the one thing that identifies them.
 *
 * The phone number is the identity. It is what the owner types to find a
 * customer again, what the panel fills the rest of the form in from, and what
 * keeps one person from being recorded several times because they wrote their
 * number in a different shape each time.
 *
 * Every operation on a number therefore starts by reducing it to the one form
 * the shop agrees on — `+212` and the nine national digits — through
 * `PhoneNumber`. Nothing here compares, stores or searches a raw number.
 */
class CustomerService
{
    /**
     * How many national digits have to be typed before the panel starts
     * looking.
     *
     * Three is "061" — enough to narrow a list down, and short enough that the
     * owner is still typing a number they know by heart. Fewer than that
     * matches most of the shop's customers and helps nobody.
     */
    public const MIN_SEARCH_DIGITS = 3;

    /**
     * The customer behind a number, or null when nobody is.
     */
    public function findByPhone(string $phone): ?Customer
    {
        $normalised = PhoneNumber::normalise($phone);

        if ($normalised === null) {
            return null;
        }

        return Customer::query()->forPhone($normalised)->first();
    }

    /**
     * The customers a term could mean.
     *
     * With no term this is the customers the shop saw most recently: the
     * dropdown starts with rows rather than empty, because the owner is
     * usually recording an order for somebody they have dealt with before,
     * and a list that says "type to search" says nothing.
     *
     * A term is either a number typed as it is spoken — a **prefix**, because
     * a number is typed from the left — or a name. The exact number, when the
     * term is one, comes first: it is the one the owner meant.
     *
     * @return Collection<int, Customer>
     */
    public function search(string $term, int $limit = 10): Collection
    {
        $term = trim($term);

        if ($term === '') {
            return Customer::query()
                ->orderByDesc('id')
                ->limit($limit)
                ->get();
        }

        $matches = new Collection;

        // The exact number, when the term is one.
        $exact = PhoneNumber::normalise($term);

        if ($exact !== null) {
            $match = Customer::query()->forPhone($exact)->first();

            if ($match) {
                $matches->push($match);
            }
        }

        // The numbers containing what was typed.
        //
        // A **fragment**, not a prefix: the owner remembers "45678" or "2345"
        // more often than the whole number, and the middle of a number is the
        // part they are surest of. Both the digits as typed and the digits
        // with the country code — or the leading zero — taken off are looked
        // for, because the number is stored one way and typed another.
        $digits = PhoneNumber::searchDigits($term);

        $usable = array_filter($digits, fn (string $d) => strlen($d) >= self::MIN_SEARCH_DIGITS);

        if ($usable !== []) {
            $matches = $matches->concat(
                Customer::query()
                    ->where(function ($q) use ($usable) {
                        foreach ($usable as $candidate) {
                            $q->orWhere('phone_normalised', 'like', '%'.$candidate.'%');
                        }
                    })
                    ->when($matches->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $matches->pluck('id')))
                    ->limit($limit)
                    ->get(),
            );
        }

        // And the names. A number too short to narrow anything still finds
        // the person whose name it spells.
        $matches = $matches->concat(
            Customer::query()
                ->where('name', 'like', '%'.$term.'%')
                ->when($matches->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $matches->pluck('id')))
                ->orderBy('name')
                ->limit($limit)
                ->get(),
        );

        return $matches->take($limit)->values();
    }

    /**
     * Record a customer, or refresh the one already known.
     *
     * The last order wins on the name, the city and the address: a customer
     * who moved is at their new address now, and an old one would send a
     * parcel to somewhere they no longer live.
     *
     * Returns null when the number cannot be normalised — there is no such
     * thing as a customer without a usable number.
     */
    public function record(
        string $name,
        string $phone,
        ?string $city = null,
        ?string $address = null,
    ): ?Customer {
        $normalised = PhoneNumber::normalise($phone);

        if ($normalised === null) {
            Log::warning('customer_phone_not_normalised', ['phone' => $phone]);

            return null;
        }

        $customer = Customer::query()->forPhone($normalised)->first() ?? new Customer;

        // The canonical form, so every number the shop holds reads the same
        // way: ten characters, the leading zero, no country code.
        $customer->name = trim($name);
        $customer->phone = $normalised;
        $customer->phone_normalised = $normalised;
        $customer->city = $city ?: null;
        $customer->address = $address ?: null;

        try {
            $customer->save();
        } catch (UniqueConstraintViolationException) {
            // Two orders for a number nobody had ordered from before arrived at
            // once. Both read "no such customer", both tried to insert, and the
            // unique index rejected the second. The index is doing its job; the
            // loser of the race retries as the update it effectively was.
            $customer = Customer::query()->forPhone($normalised)->firstOrFail();

            $customer->name = trim($name);
            $customer->phone = $normalised;
            $customer->city = $city ?: null;
            $customer->address = $address ?: null;
            $customer->save();
        }

        return $customer;
    }
}
