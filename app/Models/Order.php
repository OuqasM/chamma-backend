<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference',
        'locale',
        'first_name',
        'last_name',
        'customer_name',
        'phone',
        'phone_normalised',
        'city',
        'address',
        'notes',
        'payment_method',
        'payment_status',
        'subtotal',
        'shipping_cost',
        'total',
        'status',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'shipping_cost' => 'decimal:2',
        'total' => 'decimal:2',
        'status' => OrderStatus::class,
    ];

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function getStatusLabelAttribute(): string
    {
        return $this->status->label($this->locale);
    }

    public function getItemCountAttribute(): int
    {
        return (int) $this->items->sum('quantity');
    }

    /**
     * What the order earned: its total minus what the goods cost.
     *
     * The purchase price is read from the line's own snapshot, not from the
     * catalogue, so the benefit of an order is the benefit it had when it was
     * placed and does not change when a product is repriced.
     */
    public function getBenefitAttribute(): float
    {
        $cost = (float) $this->items->sum(
            fn ($item) => (float) $item->unit_cost * (int) $item->quantity
        );

        return round((float) $this->total - $cost, 2);
    }

    /**
     * What the goods in the order cost the shop.
     */
    public function getCostAttribute(): float
    {
        return round((float) $this->items->sum(
            fn ($item) => (float) $item->unit_cost * (int) $item->quantity
        ), 2);
    }
}
