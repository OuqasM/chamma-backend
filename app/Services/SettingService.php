<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Store settings the owner edits from the admin panel.
 *
 * Reads are cached because they happen on the checkout request path, and an
 * owner toggling a setting should see it take effect without waiting for a cache
 * expiry. Writes therefore forget the cache.
 *
 * Defaults live here rather than in the database: a fresh install has no rows,
 * and "no rows" must still mean "the store behaves sensibly" rather than null
 * warnings through the checkout.
 */
class SettingService
{
    /**
     * How long a cached setting block is good for. Short enough that a manual
     * cache clear is not needed after editing, long enough that one checkout is
     * not three extra queries.
     */
    private const TTL = 300;

    /**
     * Recipients for the new order alert, stored as a comma separated list.
     */
    public const ORDER_NOTIFICATION_EMAILS = 'order_notification_emails';

    /**
     * Master switch for the new order alert.
     */
    public const ORDER_NOTIFICATION_ENABLED = 'order_notification_enabled';

    /**
     * Who gets told. A list rather than a single address because a small store
     * usually wants this to reach two people, and one of them being on leave
     * should not mean nobody is told.
     */
    private const DEFAULTS = [
        // Off until the owner fills in an address. A notification nobody
        // configured must not be sent to a guessed recipient.
        self::ORDER_NOTIFICATION_ENABLED => '0',
        self::ORDER_NOTIFICATION_EMAILS => '',
    ];

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return Cache::remember('store.settings', self::TTL, function () {
            $stored = Setting::query()->pluck('value', 'key')->all();

            return array_merge(self::DEFAULTS, $stored);
        });
    }

    public function get(string $key, ?string $default = null): ?string
    {
        return $this->all()[$key] ?? $default;
    }

    public function set(string $key, ?string $value): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);

        $this->forget();
    }

    /**
     * @param  array<string, string|null>  $values
     */
    public function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }

        $this->forget();
    }

    /**
     * Every order alert recipient, normalised and deduplicated.
     *
     * Addressed to a list rather than a single string because a comma, a space,
     * a newline or a trailing comma in the admin form should all mean the same
     * thing. An unparseable entry is dropped rather than passed to the mailer,
     * where it would fail the whole notification — one bad paste losing every
     * real recipient is the wrong failure mode.
     *
     * @return list<string>
     */
    public function orderNotificationEmails(): array
    {
        $configured = $this->get(self::ORDER_NOTIFICATION_EMAILS) ?? '';

        $emails = collect(preg_split('/[,;\s]+/', $configured) ?: [])
            ->map(fn (string $email) => strtolower(trim($email)))
            ->filter(fn (string $email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            // An owner who turns the alert off often clears the address in the
            // same breath; an enabled toggle with no recipient is a
            // misconfiguration, not a reason to mail the default contact.
            ->values()
            ->all();

        return $emails;
    }

    public function orderNotificationsEnabled(): bool
    {
        if (! $this->orderNotificationEmails()) {
            return false;
        }

        // Compared as a string, not cast with filter_var, so the only truthy
        // value is the one the admin toggle writes.
        return $this->get(self::ORDER_NOTIFICATION_ENABLED) === '1';
    }

    private function forget(): void
    {
        Cache::forget('store.settings');
    }
}
