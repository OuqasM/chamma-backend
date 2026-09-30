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
            // Reply-to, not a recipient. When the shopper gave an address,
            // replying to the alert should open a mail to them rather than back
            // to the store's own inbox asking who wrote.
            replyTo: $this->order->email ? [$this->order->email] : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.order',
            with: [
                'order' => $this->order,
                'customerEmail' => $this->order->email,
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
     *
     * The customer's own address, when they gave one, is set as reply-to rather
     * than as a recipient: replying to an alert should open a mail to the
     * shopper. Without it, Reply lands on the store's own inbox asking who
     * wrote to them.
     */
    private function adminUrl(): string
    {
        $base = rtrim((string) config('app.url'), '/');
        $locale = config('chamma.default_locale', 'fr');

        return "{$base}/{$locale}/admin/orders";
    }
}
