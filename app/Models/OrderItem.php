<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class OrderItem extends Model
{
    /**
     * An order line is an immutable snapshot written once, so it carries no
     * created_at/updated_at columns.
     */
    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'product_id',
        'product_name',
        'product_slug',
        'product_sku',
        'variant',
        'product_image',
        'locale',
        'unit_price',
        // What the shop paid for the unit when the order was placed. A
        // snapshot like the selling price: the benefit of an order is its
        // total minus what the goods cost, and that has to stay true after
        // the catalogue is repriced.
        'unit_cost',
        'quantity',
        'subtotal',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'quantity' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function getImageUrlAttribute(): ?string
    {
        if (! $this->product_image) {
            return null;
        }

        return Storage::disk(config('chamma.disk', 'storefront'))->url($this->product_image);
    }
}
