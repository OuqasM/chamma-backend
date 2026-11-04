<?php

namespace App\Models;

use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One person the shop sells to.
 *
 * @property int $id
 * @property string $name
 * @property string $phone
 * @property string $phone_normalised
 * @property ?string $city
 * @property ?string $address
 */
class Customer extends Model
{
    protected $fillable = [
        'name',
        'phone',
        'phone_normalised',
        'city',
        'address',
    ];

    /**
     * The orders this customer placed.
     *
     * Joined on the reduced number rather than the typed one: a customer's
     * orders carry the number in whichever shape they were placed with — a
     * website order keeps what the customer typed, an order typed in the panel
     * keeps the reduced form — and they are all the same handset.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'phone_normalised', 'phone_normalised');
    }

    /**
     * The one customer behind a number, whatever shape it was written in.
     */
    public function scopeForPhone(Builder $query, string $normalised): Builder
    {
        return $query->where('phone_normalised', $normalised);
    }

    /**
     * The `wa.me` URL for this number, for the panel's contact button.
     *
     * Built through the normaliser rather than off `phone_normalised` because
     * wa.me wants bare digits: it rejects both the `+` of `+2126...` and the
     * leading zero a national number was typed with.
     */
    public function whatsappUrl(): string
    {
        return 'https://wa.me/'.PhoneNumber::whatsappDigits($this->phone_normalised);
    }
}
