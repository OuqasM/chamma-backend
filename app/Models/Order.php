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
     * What the goods in the order cost the shop.
     *
     * Null until the order is **delivered**: the cost is settled when the sale
     * happens. An order the owner writes in the panel is delivered from the
     * start, so it is settled at once; a website order is settled when it is
     * moved to delivered.
     *
     * The figure is read from the line's own snapshot, which is written when
     * the order is settled — and re-written when the owner rectifies a
     * product's purchase price, so that the benefit of the orders containing
     * it follows the correction.
     */
    public function getCostAttribute(): ?float
    {
        if ($this->status !== OrderStatus::Delivered) {
            return null;
        }

        return round((float) $this->items->sum(
            fn ($item) => (float) $item->unit_cost * (int) $item->quantity
        ), 2);
    }

    /**
     * What the order earned: its total minus what the goods cost.
     */
    public function getBenefitAttribute(): ?float
    {
        $cost = $this->cost;

        if ($cost === null) {
            return null;
        }

        return round((float) $this->total - $cost, 2);
    }
}
