<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One visitor, and the state of their most recent visit.
 *
 * A row is created on the first page view and updated in place on every one
 * after it, so the table holds one line per visitor rather than a log. See
 * `VisitorTracker` for how the identity and the device are derived.
 */
class Visit extends Model
{
    protected $fillable = [
        'visitor_id',
        'ip',
        'device',
        'browser',
        'os',
        'path',
        'referrer',
        'locale',
        'visits_count',
        'first_seen',
        'last_seen',
    ];

    protected $casts = [
        'visits_count' => 'integer',
        'first_seen' => 'datetime',
        'last_seen' => 'datetime',
    ];

    /**
     * Only the three device classes the panel filters on. Kept as a scope
     * rather than an enum because the value is derived from a user-agent string
     * that is not under our control, and an unexpected value should degrade to
     * `other` rather than throw.
     */
    public function scopeDevice(Builder $query, string $device): Builder
    {
        return $query->where('device', $device);
    }

    public function scopeRecent(Builder $query): Builder
    {
        return $query->orderByDesc('last_seen')->orderByDesc('id');
    }
}
