<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What a parcel to one city costs the shop.
 *
 * The price the customer is charged for the delivery, the price the courier
 * is paid, and the figure every order to that city is settled against. One
 * price per city, kept by the owner: the carrier's own tariff until they
 * decide otherwise.
 *
 * @property int $id
 * @property string $city
 * @property float $fee
 */
class DeliveryFee extends Model
{
    protected $fillable = [
        'city',
        'fee',
    ];

    protected $casts = [
        'fee' => 'decimal:2',
    ];
}
