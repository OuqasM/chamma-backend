<?php

namespace App\Services;

use App\Mail\NewOrderNotification;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the new order alert to the addresses the owner configured.
 *
 * The one rule here: a notification problem must never become a checkout
 * problem. An order is money and the shopper is on the confirmation screen
 * waiting; an SMTP timeout on the host is not worth failing that over. So every
 * failure is caught and logged with the order reference, which is enough for the
 * owner to notice the alert did not arrive and check the log.
 *
 * With no queue worker on shared hosting this runs inline. To keep that from
 * costing the shopper a visible wait, Laravel's `afterResponse` defers it until
 * the JSON has been flushed to the browser.
 */
class OrderNotifier
{
    public function __construct(
        private readonly SettingService $settings,
    ) {}

    /**
     * @return bool Whether an alert was actually handed to the mailer. Logged,
     *              not returned to the controller, so a caller cannot mistake
     *              a failed notification for a failed order.
     */
    public function notifyNewOrder(Order $order): bool
    {
        if (! $this->settings->orderNotificationsEnabled()) {
            return false;
        }

        $recipients = $this->settings->orderNotificationEmails();

        if ($recipients === []) {
            return false;
        }

        try {
            Mail::to($recipients)->send(new NewOrderNotification($order));
        } catch (\Throwable $e) {
            // The order is already committed at this point, so this is a
            // reporting failure, never a checkout failure.
            Log::warning('order_notification_failed', [
                'reference' => $order->reference,
                'recipients' => $recipients,
                'message' => $e->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Test and console hook: does this store have anywhere to send an alert?
     */
    public function isConfigured(): bool
    {
        return $this->settings->orderNotificationsEnabled();
    }
}
