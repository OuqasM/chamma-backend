<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A customer review: one screenshot of a WhatsApp conversation.
 *
 * Deliberately the barest model in the shop — an image, a place in the order,
 * an on/off switch. No translations, because a conversation is not translated;
 * no slug, because the reviews page shows all of them and none is addressed
 * individually.
 *
 * The table is `review_screenshots` rather than `reviews`: the October
 * scaffold left an unrelated, orphaned `reviews` table behind in databases
 * that ran its since-deleted migration, and the name would have collided
 * with it.
 */
class ReviewScreenshot extends Model
{
    protected $fillable = [
        'image',
        'position',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'position' => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * The owner's order: edited positions first, never-numbered reviews — the
     * column default, 0 — last in creation order. The same convention
     * `Brand::scopeOrdered()` implements, so one mental model covers every
     * list the panel can order.
     */
    public function scopeOrdered($query)
    {
        return $query
            ->orderByRaw('position = 0')
            ->orderBy('position')
            ->orderByRaw('case when position = 0 then id else 0 end')
            ->orderBy('id');
    }
}
