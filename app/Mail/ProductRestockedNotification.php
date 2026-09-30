<?php

namespace App\Mail;

use App\Models\Product;
use App\Models\WaitlistEntry;
use App\Support\StorefrontUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Tells the store that a product people asked about is available again.
 *
 * The whole point of the waiting list is that someone said "tell me when it's
 * back", so this message exists to make calling them the next five minutes
 * rather than a task to be remembered. The numbers are therefore the email
 * body and not an attachment, and each one is a wa.me link the owner can open
 * from their mail client: for a Moroccan store, a link that starts a
 * conversation beats a number they have to retype.
 *
 * Sent inline, for the same reason as the order alert — no queue worker on
 * shared hosting. This one runs after an admin action rather than on a
 * customer's request, so there is no shopper waiting on it.
 */
class ProductRestockedNotification extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  Collection<int, WaitlistEntry>  $entries
     */
    public function __construct(
        public readonly Product $product,
        public readonly Collection $entries,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('mail.restock.subject', [
                'name' => $this->product->name(config('chamma.default_locale', 'fr')),
                'count' => $this->entries->count(),
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.restock',
            with: [
                'product' => $this->product,
                'entries' => $this->entries,
                'adminUrl' => $this->adminUrl(),
                'storeUrl' => $this->storeUrl(),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }

    /**
     * The product's row in the panel, already filtered to this product.
     */
    private function adminUrl(): string
    {
        return StorefrontUrl::to(sprintf(
            '/%s/admin/waitlist?product=%d',
            config('chamma.default_locale', 'fr'),
            $this->product->id,
        ));
    }

    /**
     * The product page itself, so the owner can check what they are promising
     * is back before they start dialling.
     */
    private function storeUrl(): string
    {
        return StorefrontUrl::to(sprintf(
            '/%s/products/%s',
            config('chamma.default_locale', 'fr'),
            $this->product->slug(),
        ));
    }
}
