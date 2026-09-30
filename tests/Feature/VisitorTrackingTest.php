<?php

namespace Tests\Feature;

use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;
use Tests\TestCase;

/**
 * The visitor table.
 *
 * The behaviour that matters is the one that is easy to get wrong: a second
 * visit must update the existing row rather than adding another, and it must
 * not move `first_seen` or reset the counter. Most of these tests assert that
 * explicitly, because a duplicate row would quietly inflate every count the
 * panel shows.
 */
class VisitorTrackingTest extends TestCase
{
    use RefreshDatabase;

    private const DESKTOP = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15';

    private const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

    private const IPAD = 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/604.1';

    private const CHROME_ANDROID = 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36';

    public function test_a_storefront_page_view_creates_one_visit(): void
    {
        $this->getJson('/api/fr/home')->assertOk();

        $this->assertSame(1, Visit::query()->count());
    }

    public function test_a_repeat_visit_updates_the_same_row_instead_of_adding_one(): void
    {
        $first = $this->getJson('/api/fr/home')->assertOk();

        $visitorId = $this->issuedVisitorId($first);

        $this->withCredentials()
            ->withUnencryptedCookie('chamma_visitor', $visitorId)
            ->getJson('/api/fr/products')
            ->assertOk();

        $this->assertSame(1, Visit::query()->count(), 'a returning visitor created a second row');
    }

    /**
     * The whole point of "keep the last visit": the row carries the newest
     * state, while the counter and the original arrival are preserved.
     */
    public function test_a_repeat_visit_keeps_the_last_state_and_bumps_the_count(): void
    {
        $response = $this->getJson('/api/fr/home')->assertOk();

        $visitorId = $this->issuedVisitorId($response);
        $first = Visit::query()->firstOrFail();

        $this->withCredentials()
            ->withUnencryptedCookie('chamma_visitor', $visitorId)
            ->getJson('/api/fr/categories')
            ->assertOk();

        $this->travel(3)->hours();

        $this->withCredentials()
            ->withUnencryptedCookie('chamma_visitor', $visitorId)
            ->getJson('/api/fr/offers')
            ->assertOk();

        $visit = Visit::query()->firstOrFail();

        $this->assertSame(3, $visit->visits_count, 'the running total did not advance');
        // The most recent path is what the panel shows.
        $this->assertSame('offers', $visit->path);
        $this->assertTrue(
            $first->first_seen->equalTo($visit->first_seen),
            'first_seen moved on a return visit',
        );
        $this->assertTrue(
            $visit->last_seen->greaterThan($first->last_seen),
            'last_seen did not advance',
        );
    }

    public function test_the_identity_cookie_is_issued_to_a_new_visitor(): void
    {
        $response = $this->getJson('/api/fr/home')->assertOk();

        $cookie = collect($response->headers->getCookies())
            ->first(fn ($c) => $c->getName() === 'chamma_visitor');

        $this->assertNotNull($cookie, 'no identity cookie was set');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $cookie->getValue());
        // `getExpiresTime()` is the absolute expiry; the lifetime is a year.
        $this->assertEqualsWithDelta(
            now()->addYear()->getTimestamp(),
            $cookie->getExpiresTime(),
            60,
        );
        // The token must not be readable by scripts, or an XSS bug would hand a
        // stable identifier to whoever triggered it.
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
    }

    /**
     * Two different visitors must not be collapsed into one row, which is what
     * would happen if the identity were the IP alone.
     */
    public function test_two_visitors_with_the_same_ip_are_stored_separately(): void
    {
        $this->getJson('/api/fr/home')->assertOk();
        $this->assertCount(1, Visit::query()->get());

        // A second request carrying no identity cookie is a different browser,
        // which is how the test client behaves by default: cookies the *server*
        // set are not fed back into later requests, only ones the test sets
        // explicitly. Nothing needs clearing here.
        $this->getJson('/api/fr/home')->assertOk();

        $this->assertSame(2, Visit::query()->count(), 'two browsers behind one IP were merged');
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public static function deviceProvider(): array
    {
        return [
            'desktop safari' => [self::DESKTOP, 'desktop', 'Safari'],
            'iphone safari' => [self::IPHONE, 'mobile', 'Safari'],
            'ipad safari' => [self::IPAD, 'tablet', 'Safari'],
            'android chrome' => [self::CHROME_ANDROID, 'mobile', 'Chrome'],
        ];
    }

    /**
     * @dataProvider deviceProvider
     */
    public function test_the_device_and_browser_are_derived_from_the_user_agent(string $agent, string $device, string $browser): void
    {
        $this->withServerVariables(['HTTP_USER_AGENT' => $agent])
            ->getJson('/api/fr/home')
            ->assertOk();

        $visit = Visit::query()->firstOrFail();

        $this->assertSame($device, $visit->device);
        $this->assertSame($browser, $visit->browser);
    }

    public function test_an_unknown_agent_falls_back_to_other_rather_than_desktop(): void
    {
        $this->withServerVariables(['HTTP_USER_AGENT' => 'SomeUnknownClient/1.0'])
            ->getJson('/api/fr/home')
            ->assertOk();

        $this->assertSame('other', Visit::query()->firstOrFail()->device);
    }

    public function test_bots_are_not_recorded(): void
    {
        $this->withServerVariables(['HTTP_USER_AGENT' => 'Mozilla/5.0 (compatible; Googlebot/2.1)'])
            ->getJson('/api/fr/home')
            ->assertOk();

        $this->assertSame(0, Visit::query()->count(), 'a crawler was recorded as a visitor');
    }

    /**
     * A cart submission is not a page view. The quote endpoint accepts an empty
     * basket — it totals zero rather than rejecting — so the status is not what
     * is being asserted here; only the absence of a row is.
     */
    public function test_write_requests_are_not_counted_as_visits(): void
    {
        $this->postJson('/api/fr/checkout/quote', [])->assertOk();
        $this->postJson('/api/fr/checkout', [])->assertStatus(422);

        $this->assertSame(0, Visit::query()->count(), 'a POST was counted as a page view');
    }

    public function test_an_order_lookup_is_not_counted(): void
    {
        $this->getJson('/api/fr/orders/NOPE')->assertStatus(404);

        $this->assertSame(0, Visit::query()->count());
    }

    public function test_the_locale_is_recorded_from_the_route(): void
    {
        $this->getJson('/api/ar/home')->assertOk();

        $this->assertSame('ar', Visit::query()->firstOrFail()->locale);
        $this->assertSame('home', Visit::query()->firstOrFail()->path);
    }

    public function test_a_malformed_cookie_is_replaced_rather_than_rejected(): void
    {
        $this->withUnencryptedCookie('chamma_visitor', 'not-a-valid-token')
            ->getJson('/api/fr/home')
            ->assertOk();

        $visit = Visit::query()->firstOrFail();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $visit->visitor_id);
    }

    public function test_the_referrer_is_recorded(): void
    {
        $this->withServerVariables(['HTTP_REFERER' => 'https://www.instagram.com/'])
            ->getJson('/api/fr/home')
            ->assertOk();

        $this->assertSame('https://www.instagram.com/', Visit::query()->firstOrFail()->referrer);
    }

    /**
     * The identity the server handed to a browser on a given response.
     *
     * Taken from the response rather than the database, because the database
     * holds the resolved id and reading it back would let a test pass even if
     * the cookie were never sent — which is exactly the bug this suite was
     * written to catch.
     */
    private function issuedVisitorId(TestResponse $response): string
    {
        $cookie = collect($response->headers->getCookies())
            ->first(fn (SymfonyCookie $c) => $c->getName() === 'chamma_visitor');

        $this->assertNotNull($cookie, 'no identity cookie was issued');

        return $cookie->getValue();
    }
}
