<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One shopper's cart as it last stood, and whether it became an order.
 *
 * A row is created by the first sync and updated in place on every change after
 * it, so the table holds one line per cart rather than a log of every edit. The
 * name is a snapshot of what the shopper saw, including its locale, because an
 * abandoned cart from an Arabic session has to be read in Arabic to mean
 * anything.
 *
 * The row carries no email, name, or phone. Whoever filled it did not hand over
 * their details, and the report below works without them. Contact details arrive
 * with the order and the two tables meet at `order_id`.
 */
class Cart extends Model
{
    /**
     * How long a cart must sit untouched before the report will call it
     * abandoned rather than open.
     *
     * Someone who is filling a cart right now is not an abandoned cart, and
     * reporting them as one would make the number meaningless. An hour is long
     * enough that a tab left open over lunch is still "open", and short enough
     * that an owner looking at the panel in the evening is not looking at
     * yesterday's live session.
     */
    public const IDLE_HOURS = 1;

    protected $fillable = [
        'visitor_id',
        'locale',
        'subtotal',
        'items_count',
        'updates_count',
        'converted_at',
        'order_id',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'items_count' => 'integer',
        'updates_count' => 'integer',
        'converted_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The visitor whose identity token this cart belongs to.
     *
     * Deliberately not an Eloquent relation: `visits` has no primary key that
     * joins cleanly on its own, and a `belongsTo` through a non-unique column
     * would be a silent many-to-one. The report reads the address with an
     * explicit left join instead, which also keeps the cart rows free of network
     * data.
     */
    public function visit(): ?Visit
    {
        return Visit::query()->where('visitor_id', $this->visitor_id)->first();
    }

    /**
     * Carts that became orders.
     */
    public function scopeConverted(Builder $query): Builder
    {
        return $query->whereNotNull('converted_at');
    }

    /**
     * Carts that did not. A cart is "not converted" whether it was abandoned an
     * hour ago or a moment ago; `scopeIdle` narrows that to the abandoned ones.
     */
    public function scopeUnconverted(Builder $query): Builder
    {
        return $query->whereNull('converted_at');
    }

    /**
     * Untouched for longer than the idle window, so a shopper mid-cart is not
     * counted as lost.
     */
    public function scopeIdle(Builder $query, ?int $hours = null): Builder
    {
        $hours ??= self::IDLE_HOURS;

        // Qualified: the admin report left-joins `visits` onto this scope to show
        // the address behind a cart, and `visits` has an `updated_at` of its
        // own. Unqualified, that join turns this into an ambiguous-column error
        // on MySQL — and SQLite, which the suite runs on, resolves it silently,
        // so the bug only appears in production.
        return $query->where('carts.updated_at', '<=', now()->subHours($hours));
    }

    /**
     * The two states the report's filter offers, in the order an owner reads
     * them: what is being lost, then what was won.
     */
    public function scopeAbandoned(Builder $query, ?int $hours = null): Builder
    {
        return $query->unconverted()->idle($hours);
    }

    public function scopeRecent(Builder $query): Builder
    {
        return $query->orderByDesc('carts.updated_at')->orderByDesc('carts.id');
    }
}
