<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One line in a cart, as it was at the moment the cart was last synced.
 *
 * The name, slug, and price are snapshots rather than references, for the reason
 * `orders` items are: a cart is only useful as a record of what someone wanted
 * and what it cost them at the time. Re-pricing the perfume next month must not
 * change the value of a cart from last Tuesday, and a deleted product must not
 * make the line unreadable.
 */
class CartItem extends Model
{
    protected $fillable = [
        'cart_id',
        'product_id',
        'product_name',
        'product_slug',
        'product_sku',
        'product_image',
        'unit_price',
        'quantity',
        'subtotal',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'quantity' => 'integer',
    ];

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
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
