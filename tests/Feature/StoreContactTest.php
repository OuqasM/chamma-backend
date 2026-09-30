<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The footer's social links depend on this payload, and the failure mode is
 * silent: a missing key just means the link does not render, so nothing looks
 * broken at a glance.
 */
class StoreContactTest extends TestCase
{
    // The navigation payload lists brands and categories, so the schema has to
    // exist for the endpoint to be reached at all.
    use RefreshDatabase;

    public function test_store_bundle_exposes_contact(): void
    {
        $contact = $this->get('/api/store')->assertOk()->json('contact');

        $this->assertIsArray($contact);

        foreach (['email', 'phone', 'whatsapp', 'instagram', 'facebook', 'tiktok', 'address'] as $key) {
            $this->assertArrayHasKey($key, $contact, "contact.$key is missing");
        }
    }

    public function test_navigation_bootstrap_exposes_contact_for_every_locale(): void
    {
        foreach (['fr', 'ar', 'en'] as $locale) {
            $contact = $this->getJson("/api/{$locale}/navigation")
                ->assertOk()
                ->json('contact');

            $this->assertIsArray($contact, "no contact block for {$locale}");
            $this->assertArrayHasKey('whatsapp', $contact);
            $this->assertArrayHasKey('instagram', $contact);
            $this->assertArrayHasKey('tiktok', $contact);
        }
    }

    /**
     * The address is the only locale-dependent field, and it is the reason the
     * footer reads it from `/navigation` rather than from the unscoped
     * `/store` bundle: a French visitor must not be shown the Arabic address.
     */
    public function test_address_is_translated_per_locale(): void
    {
        $fr = $this->getJson('/api/fr/navigation')->assertOk()->json('contact.address');
        $ar = $this->getJson('/api/ar/navigation')->assertOk()->json('contact.address');
        $en = $this->getJson('/api/en/navigation')->assertOk()->json('contact.address');

        $this->assertNotEmpty($fr);
        $this->assertNotEmpty($ar);
        $this->assertNotEmpty($en);
        $this->assertNotSame($fr, $ar, 'French and Arabic resolved to the same address');
        $this->assertNotSame($en, $ar, 'English and Arabic resolved to the same address');
    }

    /**
     * The storefront builds `https://wa.me/<digits>`, so a formatted number left
     * in config would produce a link with spaces in it.
     */
    public function test_whatsapp_is_digits_only(): void
    {
        $whatsapp = (string) config('chamma.store.whatsapp');

        $this->assertNotSame('', $whatsapp, 'store.whatsapp is empty, the footer link will not render');
        $this->assertMatchesRegularExpression('/^\d+$/', $whatsapp, 'store.whatsapp must be digits only');
    }

    /**
     * Receipts and the footer link must not drift onto two different numbers.
     */
    public function test_store_and_payment_whatsapp_agree(): void
    {
        $this->assertSame(
            preg_replace('/\D+/', '', (string) config('chamma.store.whatsapp')),
            preg_replace('/\D+/', '', (string) config('chamma.payment.whatsapp')),
            'the footer and the transfer receipts point at different numbers',
        );
    }

    /**
     * Handles, not URLs: a full URL here would be passed straight through by the
     * storefront and would commit tracking parameters from a QR code.
     */
    public function test_social_values_are_handles_not_urls(): void
    {
        foreach (['instagram', 'tiktok', 'facebook'] as $network) {
            $value = (string) config("chamma.store.{$network}");

            $this->assertStringNotContainsStringIgnoringCase('http', $value, "store.{$network} is a URL, not a handle");
            $this->assertStringNotContainsString('?', $value, "store.{$network} carries a query string");
        }
    }
}
