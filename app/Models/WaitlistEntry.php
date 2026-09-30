<?php

namespace App\Models;

use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One person waiting for one product to come back.
 *
 * @property int $id
 * @property int $product_id
 * @property string $phone
 * @property string $phone_normalised
 * @property string $locale
 * @property ?Carbon $notified_at
 * @property ?string $notified_channel
 */
class WaitlistEntry extends Model
{
    protected $fillable = [
        'product_id',
        'phone',
        'phone_normalised',
        'locale',
        'notified_at',
        'notified_channel',
    ];

    protected $casts = [
        'notified_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The entries the owner still has to act on.
     *
     * A notified row is kept rather than deleted: it is the record that this
     * person was called, which is what stops the same number being called again
     * for a restock they already heard about.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('notified_at');
    }

    public function scopeNotified(Builder $query): Builder
    {
        return $query->whereNotNull('notified_at');
    }

    /**
     * Newest first, with the id as a tie-break so pagination is stable when
     * several entries share a timestamp — which they do, because a signup
     * burst on one product lands in the same second.
     */
    public function scopeRecent(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * The `wa.me` URL for this number, for the panel's call button.
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
