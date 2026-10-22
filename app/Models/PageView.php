<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One page view: a single, immutable fact.
 *
 * A row is written when a storefront page is served and never revised, so
 * the table reads as a log rather than as state. There is no `first_seen` or
 * running total here — those are answers a query computes over the log
 * (the earliest and latest rows of a visitor, `count(*)` of their rows), not
 * columns a write path has to guard. What a page view did have to answer
 * before now lives in `VisitorTracker`, which is the only writer.
 *
 * The model is the mirror opposite of the old one-row-per-visitor `visits`
 * table: there, the hard part was updating in place without races; here the
 * hard part doesn't exist, and pruning is what keeps the table bounded.
 */
class PageView extends Model
{
    use MassPrunable;

    /**
     * Rows are never revised, so there is no `updated_at` to stamp. The
     * property rather than `timestamps` off: the model should keep stamping
     * `created_at` for any writer that supplies no timestamp of its own.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'visitor_id',
        'ip',
        'device',
        'browser',
        'os',
        'path',
        'referrer',
        'locale',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /**
     * Only the three device classes the panel filters on. Kept as a scope
     * rather than an enum because the value is derived from a user-agent
     * string that is not under our control, and an unexpected value should
     * degrade to `other` rather than throw.
     */
    public function scopeDevice(Builder $query, string $device): Builder
    {
        return $query->where('device', $device);
    }

    /**
     * Newest first, with `id` as a tiebreak so two views recorded inside the
     * same storage timestamp page in a stable order.
     */
    public function scopeRecent(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * The log a page view is allowed to grow to.
     *
     * `model:prune` (scheduled daily in routes/console.php) removes older
     * rows: a page view carries an IP address, so it is personal data with a
     * retention window rather than a record to keep forever. MassPrunable
     * rather than Prunable because a log has no observers to fire for.
     */
    public function prunable(): Builder
    {
        $days = (int) config('chamma.visits.retention_days', 365);

        return static::query()->where('created_at', '<', Carbon::now()->subDays($days));
    }
}
