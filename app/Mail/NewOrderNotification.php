<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells the store that an order landed.
 *
 * This is the message the owner reads while the customer is still on the
 * confirmation screen, so it leads with what has to be acted on: the reference,
 * the name to phone back, and the total to expect. Everything else is context
 * underneath.
 *
 * Deliberately not ShouldQueue: with QUEUE_CONNECTION=database and no worker
 * running on shared hosting, a queued mail would sit in the jobs table until
 * someone noticed it was stuck. Sending inline is the honest behaviour here —
 * see OrderNotifier for why that cannot break a checkout.
 */
class NewOrderNotification extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Order $order,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('mail.order.subject', [
                'reference' => $this->order->reference,
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.order',
            with: [
                'order' => $this->order,
                'items' => $this->order->items,
                'adminUrl' => $this->adminUrl(),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }

    /**
     * Lets the owner jump straight to the order from the alert.
     */
    private function adminUrl(): string
    {
        // The admin panel is served by the storefront, not the API, so the link
        // is built from the storefront origin. APP_URL is the API's own host
        // and would send the owner to a 404.
        $base = rtrim((string) config('chamma.storefront_url'), '/');
        $locale = config('chamma.default_locale', 'fr');

        return "{$base}/{$locale}/admin/orders";
    }
}
