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
        // What the courier is paid for the parcel: a cost of the sale, whether
        // or not the customer was charged for the delivery.
        'courier_cost',
        // The owner's own figure, when the calculation does not know what they
        // know. Set, it is the benefit; taken away, the calculation stands.
        'benefit_override',
        'status',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'shipping_cost' => 'decimal:2',
        'total' => 'decimal:2',
        'courier_cost' => 'decimal:2',
        'benefit_override' => 'decimal:2',
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
     * What the order earned.
     *
     * The **total** the customer paid, minus what the goods cost, minus the
     * price of the parcel — because the courier is paid whether or not the
     * delivery was charged to the customer, and a delivery that was made
     * free is paid out of the margin like any other price.
     *
     * Null until the order is **delivered**: the cost is settled when the sale
     * happens. An order the owner writes in the panel is delivered from the
     * start, so it is settled at once; a website order is settled when it is
     * moved to delivered.
     *
     * And the figure the owner corrected stands as it is: what they set is
     * the benefit, because it knows what the sums do not — the rate the
     * supplier actually gave, the courier that charged more than the shop
     * billed. Taking the correction away gives the calculation back.
     */
    public function getBenefitAttribute(): ?float
    {
        if ($this->status !== OrderStatus::Delivered) {
            return null;
        }

        if ($this->benefit_override !== null) {
            return round((float) $this->benefit_override, 2);
        }

        $cost = $this->cost;

        if ($cost === null) {
            return null;
        }

        return round((float) $this->total - $cost - (float) ($this->courier_cost ?? 0), 2);
    }
}
