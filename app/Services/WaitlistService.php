<?php

namespace App\Services;

use App\Http\Requests\StoreWaitlistRequest;
use App\Models\Product;
use App\Models\WaitlistEntry;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

/**
 * The waiting list: who asked to be told, and when.
 *
 * Writes are idempotent per (product, phone). A customer who signs up twice is
 * one person, not two calls to make, and the second attempt is the one that
 * should win: they may have corrected a typo, or come back a week later when
 * they have decided they still want it.
 */
class WaitlistService
{
    /**
     * Record a signup, or refresh the existing one.
     *
     * @return array{entry: WaitlistEntry, created: bool}
     */
    public function add(Product $product, StoreWaitlistRequest $request, ?string $locale = null): array
    {
        return $this->addPhone(
            $product,
            (string) $request->input('phone'),
            $locale,
        );
    }

    /**
     * The same write, for a number the owner entered by hand.
     *
     * Deliberately not a separate code path: a number written down during a
     * phone call and the same number typed on the product page are one person,
     * and two code paths would eventually drift into two rows.
     *
     * @return array{entry: WaitlistEntry, created: bool}
     */
    public function addFromAdmin(Product $product, string $phone, ?string $locale = null): array
    {
        return $this->addPhone($product, $phone, $locale);
    }

    /**
     * @return array{entry: WaitlistEntry, created: bool}
     */
    private function addPhone(Product $product, string $phone, ?string $locale): array
    {
        $normalised = PhoneNumber::normalise($phone);

        if ($normalised === null) {
            // Unreachable from either entry point — the request validated the
            // same rule the normaliser implements, and the admin controller
            // checks it too. Guarded anyway because a null normalised number
            // would break the unique index that makes the merge work, and that
            // would surface as a database fault rather than a validation error.
            Log::warning('waitlist_phone_not_normalised', ['product_id' => $product->id, 'phone' => $phone]);

            $normalised = $phone;
        }

        $entry = WaitlistEntry::query()->firstOrNew([
            'product_id' => $product->id,
            'phone_normalised' => $normalised,
        ]);

        $created = ! $entry->exists;

        $entry->phone = $phone;
        $entry->locale = $locale ?: (string) config('chamma.default_locale', 'fr');

        // A returning signup clears a previous notification, so somebody who
        // asked again after being called goes back on the outstanding list.
        // Otherwise the owner would never learn they asked twice.
        $entry->notified_at = null;
        $entry->notified_channel = null;

        try {
            $entry->save();
        } catch (UniqueConstraintViolationException $e) {
            // Two requests for the same number arrived at once — a double
            // tapped button, or a retry on a slow connection. Both read "no such
            // entry", both try to insert, and the unique index rejects the
            // second. That is the index working, but it must not reach the
            // customer as a 500 for doing something harmless twice.
            //
            // So the loser of the race retries as the update it effectively
            // was. The unique index stays the thing that guarantees one person
            // per product; this only decides what the second request reports.
            $entry = WaitlistEntry::query()
                ->where('product_id', $product->id)
                ->where('phone_normalised', $normalised)
                ->firstOrFail();

            $entry->phone = $phone;
            $entry->locale = $locale ?: (string) config('chamma.default_locale', 'fr');
            $entry->notified_at = null;
            $entry->notified_channel = null;
            $entry->save();

            $created = false;
        }

        return ['entry' => $entry, 'created' => $created];
    }

    /**
     * Everyone still to be called for a product.
     *
     * @return Collection<int, WaitlistEntry>
     */
    public function pendingFor(Product $product)
    {
        return WaitlistEntry::query()
            ->where('product_id', $product->id)
            ->pending()
            ->recent()
            ->get();
    }

    /**
     * How many are outstanding, for the admin product list and the badge.
     */
    public function pendingCount(?int $productId = null): int
    {
        return WaitlistEntry::query()
            ->pending()
            ->when($productId, fn ($q) => $q->where('product_id', $productId))
            ->count();
    }
}
