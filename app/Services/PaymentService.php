<?php

namespace App\Services;

use App\Models\Order;

/**
 * Payment methods the storefront offers, and the instructions a shopper needs
 * to actually pay with them.
 *
 * Cash on delivery needs nothing. A bank transfer is settled off the site, so
 * the confirmation page hands the shopper the bank details and a WhatsApp link
 * to send the transfer receipt to, pre-filled with the order reference.
 */
class PaymentService
{
    /**
     * @return array<int, array{code: string, label: string, hint: string}>
     */
    public function methods(string $locale): array
    {
        return array_map(fn (string $code) => [
            'code' => $code,
            'label' => $this->label($code, $locale),
            'hint' => $this->hint($code, $locale),
        ], config('chamma.payment.methods', []));
    }

    public function isAvailable(string $code): bool
    {
        return in_array($code, config('chamma.payment.methods', []), true);
    }

    public function default(): string
    {
        return (string) config('chamma.payment.default', 'cod');
    }

    public function label(string $code, string $locale): string
    {
        return __('api.payment.'.$code, [], $locale);
    }

    /**
     * What the shopper has to do with this method, in their own language.
     */
    public function hint(string $code, string $locale): string
    {
        return __('api.payment.'.$code.'_hint', ['whatsapp' => $this->whatsapp()], $locale);
    }

    /**
     * A wa.me link with the receipt request already written for the shopper.
     */
    public function whatsappUrl(string $text): string
    {
        return 'https://wa.me/'.preg_replace('/\D+/', '', (string) config('chamma.payment.whatsapp'))
            .'?text='.rawurlencode($text);
    }

    public function whatsapp(): string
    {
        return (string) config('chamma.payment.whatsapp');
    }

    /**
     * Bank details plus the receipt link, for the confirmation page.
     *
     * @return array{holder: string, bank: string, iban: string, rib: string, image: string, whatsapp: string, whatsapp_url: string, message: string}
     */
    public function instructions(Order $order, string $locale): array
    {
        $bank = config('chamma.payment.bank', []);
        $amount = number_format((float) $order->total, 2, '.', ' ');

        $message = __('api.payment.receipt_message', [
            'reference' => $order->reference,
            'amount' => $amount,
            'city' => $order->city,
        ], $locale);

        return [
            'holder' => (string) ($bank['holder'] ?? ''),
            'bank' => (string) ($bank['bank'] ?? ''),
            'iban' => (string) ($bank['iban'] ?? ''),
            'rib' => (string) ($bank['rib'] ?? ''),
            'image' => (string) ($bank['image'] ?? ''),
            'whatsapp' => $this->whatsapp(),
            'whatsapp_url' => $this->whatsappUrl($message),
            'message' => $message,
        ];
    }
}
