<?php

namespace App\Services;

use App\Mail\ProductRestockedNotification;
use App\Models\Product;
use App\Models\WaitlistEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the back-in-stock alert to the addresses the owner configured.
 *
 * Same recipient list as the order alert and the same one rule: a notification
 * problem must never become a stock problem. An admin clicking "back in stock"
 * is doing bookkeeping; refusing the save because SMTP timed out would leave the
 * storefront lying about availability, which is the one thing the shop cannot
 * get wrong. So failures are caught and logged, and the stock change stands.
 *
 * The alert fires once per restock, and the caller passes the entries that were
 * outstanding at the moment of the transition. Re-saving an already-available
 * product has nobody new to tell, so it sends nothing rather than mailing the
 * same numbers again.
 *
 * Sending the mail deliberately does NOT mark the entries as contacted. The
 * alert is a prompt to pick up the telephone; nobody has done that yet. Marking
 * them here would empty the calling list the instant the email left, leaving the
 * owner with an empty panel and no record of who still had to be called. The
 * owner marks people off once they have actually reached them.
 */
class RestockNotifier
{
    public function __construct(
        private readonly SettingService $settings,
    ) {}

    /**
     * Tell the owner that people are waiting on this product.
     *
     * @param  Collection<int, WaitlistEntry>  $entries
     * @return bool Whether an alert was actually handed to the mailer. Logged,
     *              not surfaced to the admin, so a failed notification can never
     *              read as a failed stock update.
     */
    public function notifyRestocked(Product $product, Collection $entries): bool
    {
        if ($entries->isEmpty()) {
            return false;
        }

        if (! $this->settings->orderNotificationsEnabled()) {
            return false;
        }

        $recipients = $this->settings->orderNotificationEmails();

        if ($recipients === []) {
            return false;
        }

        try {
            Mail::to($recipients)->send(new ProductRestockedNotification($product, $entries));
        } catch (\Throwable $e) {
            Log::warning('restock_notification_failed', [
                'product_id' => $product->id,
                'product' => $product->slug(),
                'waiting' => $entries->count(),
                'recipients' => $recipients,
                'message' => $e->getMessage(),
            ]);

            return false;
        }

        return true;
    }
}
