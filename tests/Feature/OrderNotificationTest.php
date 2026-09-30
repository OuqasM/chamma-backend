<?php

namespace Tests\Feature;

use App\Mail\NewOrderNotification;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderNotifier;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The new order alert.
 *
 * Two things are worth protecting here, and they pull in opposite directions:
 * the owner must be told, and a mail failure must never cost the shopper their
 * order. Most of these tests are about the second.
 */
class OrderNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function settings(): SettingService
    {
        return app(SettingService::class);
    }

    private function enableAlerts(string ...$emails): void
    {
        $this->settings()->setMany([
            SettingService::ORDER_NOTIFICATION_EMAILS => implode(', ', $emails),
            SettingService::ORDER_NOTIFICATION_ENABLED => '1',
        ]);
    }

    private function admin(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));
    }

    private function product(int $price = 100, int $stock = 100): Product
    {
        $this->admin();

        $this->postJson('/api/admin/products', [
            'name' => 'Oud Royale',
            'price' => $price,
            'stock' => $stock,
        ])->assertCreated();

        return Product::query()->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function checkout(array $overrides = []): TestResponse
    {
        $product = $this->product();

        return $this->postJson('/api/fr/checkout', array_merge([
            'name' => 'Ahmed Ben Ali',
            'phone' => '0612345678',
            'city' => 'CASABLANCA',
            'address' => '12 boulevard d Anfa',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ], $overrides));
    }

    // ---------------------------------------------------------------
    // Nothing configured, nothing sent
    // ---------------------------------------------------------------

    /**
     * The default is off. A fresh install has no rows in `settings`, so an
     * unconfigured store must be quiet rather than mailing a guessed address.
     */
    public function test_nothing_is_sent_until_the_owner_configures_alerts(): void
    {
        Mail::fake();

        $this->checkout()->assertCreated();

        Mail::assertNothingSent();
    }

    public function test_an_enabled_toggle_with_no_recipient_sends_nothing(): void
    {
        Mail::fake();

        $this->settings()->set(SettingService::ORDER_NOTIFICATION_ENABLED, '1');

        $this->checkout()->assertCreated();

        Mail::assertNothingSent();
    }

    public function test_a_configured_address_with_the_toggle_off_sends_nothing(): void
    {
        Mail::fake();

        $this->settings()->set(SettingService::ORDER_NOTIFICATION_EMAILS, 'owner@chama.ma');

        $this->checkout()->assertCreated();

        Mail::assertNothingSent();
    }

    // ---------------------------------------------------------------
    // The happy path
    // ---------------------------------------------------------------

    public function test_the_owner_is_emailed_when_an_order_arrives(): void
    {
        Mail::fake();

        $this->enableAlerts('owner@chama.ma');

        $response = $this->checkout()->assertCreated();

        $reference = $response->json('order.reference');

        Mail::assertSent(NewOrderNotification::class, function ($mail) use ($reference) {
            return $mail->hasTo('owner@chama.ma')
                && str_contains($mail->envelope()->subject, $reference);
        });
    }

    public function test_every_configured_recipient_is_notified(): void
    {
        Mail::fake();

        $this->enableAlerts('one@chama.ma', 'two@chama.ma');

        $this->checkout()->assertCreated();

        Mail::assertSent(NewOrderNotification::class, fn ($mail) => $mail->hasTo('one@chama.ma') && $mail->hasTo('two@chama.ma'));
    }

    /**
     * The shopper's address is a reply-to, not a recipient: an owner replying to
     * an alert should reach the shopper, not the store's own inbox.
     */
    public function test_the_customers_email_becomes_reply_to(): void
    {
        Mail::fake();

        $this->enableAlerts('owner@chama.ma');

        $this->checkout(['email' => 'ahmed@example.com'])->assertCreated();

        Mail::assertSent(NewOrderNotification::class, function ($mail) {
            $replyTo = $mail->envelope()->replyTo;

            return array_column($replyTo, 'address') === ['ahmed@example.com'];
        });
    }

    public function test_an_order_without_a_customer_email_still_notifies_the_owner(): void
    {
        Mail::fake();

        $this->enableAlerts('owner@chama.ma');

        $this->checkout()->assertCreated();

        Mail::assertSent(NewOrderNotification::class, function ($mail) {
            return $mail->envelope()->replyTo === [];
        });
    }

    // ---------------------------------------------------------------
    // A mail failure must not cost the shopper their order
    // ---------------------------------------------------------------

    /**
     * The one that matters most. An SMTP timeout on shared hosting is an
     * infrastructure problem, and the shopper is sitting on the confirmation
     * screen waiting to find out if their order went through.
     */
    public function test_a_broken_mailer_does_not_fail_the_checkout(): void
    {
        $this->enableAlerts('owner@chama.ma');

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP connection refused'));

        Log::shouldReceive('warning')->once();

        $response = $this->checkout();

        $response->assertCreated();
        $this->assertNotNull($response->json('order.reference'));
        $this->assertSame(1, Order::query()->count());
    }

    /**
     * The warning log has to name the order, otherwise a silent failure is
     * unrecoverable: the owner knows the alert never arrived but cannot tell
     * which orders were missed.
     */
    public function test_a_failed_alert_is_logged_with_the_order_reference(): void
    {
        $this->enableAlerts('owner@chama.ma');

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP connection refused'));

        $logged = [];

        Log::shouldReceive('warning')->andReturnUsing(function ($message, $context = []) use (&$logged) {
            $logged[] = ['message' => $message, 'context' => $context];
        });

        $reference = $this->checkout()->assertCreated()->json('order.reference');

        $this->assertCount(1, $logged);
        $this->assertSame('order_notification_failed', $logged[0]['message']);
        $this->assertSame($reference, $logged[0]['context']['reference']);
    }

    /**
     * Alerts are not the only way an order is noticed; a duplicate mail to the
     * owner would be worse than none, so the notifier reports whether it sent.
     */
    public function test_the_notifier_reports_whether_it_sent(): void
    {
        Mail::fake();

        $notifier = app(OrderNotifier::class);
        $order = $this->placeOrder();

        // Unconfigured: a false here is what stops a fresh install mailing a
        // guessed address.
        $this->assertFalse($notifier->notifyNewOrder($order));
        Mail::assertNothingSent();

        $this->enableAlerts('owner@chama.ma');

        $this->assertTrue($notifier->notifyNewOrder($order));
        Mail::assertSentCount(1);
    }

    private function placeOrder(array $overrides = []): Order
    {
        $product = $this->product();

        $this->postJson('/api/fr/checkout', array_merge([
            'name' => 'Ahmed Ben Ali',
            'phone' => '0612345678',
            'city' => 'CASABLANCA',
            'address' => '12 boulevard d Anfa',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ], $overrides))->assertCreated();

        return Order::query()->firstOrFail();
    }

    // ---------------------------------------------------------------
    // Recipient parsing
    // ---------------------------------------------------------------

    /**
     * The owner pastes from wherever they keep addresses, which is to say with
     * commas, semicolons, spaces and newlines all mixed together.
     */
    public function test_recipients_are_normalised_and_deduplicated(): void
    {
        $this->settings()->set(
            SettingService::ORDER_NOTIFICATION_EMAILS,
            "One@Chama.ma, two@chama.ma ;ONE@chama.ma\nthree@chama.ma "
        );

        $this->assertSame(
            ['one@chama.ma', 'two@chama.ma', 'three@chama.ma'],
            $this->settings()->orderNotificationEmails()
        );
    }

    /**
     * One unparseable paste must not cost the valid addresses alongside it.
     */
    public function test_an_unparseable_recipient_is_dropped_without_losing_the_rest(): void
    {
        $this->settings()->set(
            SettingService::ORDER_NOTIFICATION_EMAILS,
            'good@chama.ma, not-an-email, also-good@chama.ma'
        );

        $this->assertSame(
            ['good@chama.ma', 'also-good@chama.ma'],
            $this->settings()->orderNotificationEmails()
        );
    }

    // ---------------------------------------------------------------
    // The admin endpoints
    // ---------------------------------------------------------------

    public function test_settings_require_admin_authentication(): void
    {
        $this->getJson('/api/admin/settings')->assertUnauthorized();
        $this->putJson('/api/admin/settings', [])->assertUnauthorized();
    }

    public function test_the_owner_can_read_and_save_alert_settings(): void
    {
        $this->admin();

        $this->assertFalse($this->getJson('/api/admin/settings')->assertOk()->json('settings.notifications.order_enabled'));

        $this->putJson('/api/admin/settings', [
            'notifications' => [
                'order_emails' => 'Owner@Chama.ma',
                'order_enabled' => true,
            ],
        ])->assertOk()
            ->assertJsonPath('settings.notifications.order_enabled', true)
            // Saved canonically, so the panel and the mailer never disagree
            // about who the recipients are.
            ->assertJsonPath('settings.notifications.order_emails', 'owner@chama.ma')
            ->assertJsonPath('settings.notifications.order_recipients', ['owner@chama.ma']);

        $this->assertSame(['owner@chama.ma'], $this->settings()->orderNotificationEmails());
        $this->assertTrue($this->settings()->orderNotificationsEnabled());
    }

    /**
     * A rejected address must be reported, not quietly discarded. Silently
     * dropping one the owner believes they saved is how alerts go missing for a
     * month: the toggle is on, the panel shows an address, nothing arrives.
     */
    public function test_an_invalid_recipient_is_reported_rather_than_silently_dropped(): void
    {
        $this->admin();

        $this->putJson('/api/admin/settings', [
            'notifications' => [
                'order_emails' => 'good@chama.ma, broken@',
                'order_enabled' => true,
            ],
        ])->assertStatus(422)
            ->assertJsonPath('invalid_emails.0', 'broken@');

        // Nothing was written, so the owner cannot end up with a half saved
        // list they believe is complete.
        $this->assertSame('', (string) $this->settings()->get(SettingService::ORDER_NOTIFICATION_EMAILS));
    }

    /**
     * `MAIL_MAILER=log` is this project's default, and it writes mail to a log
     * file instead of sending it. An owner needs to be able to see that.
     */
    public function test_the_panel_reports_why_alerts_may_not_be_arriving(): void
    {
        $this->admin();

        config(['mail.default' => 'log']);

        $this->getJson('/api/admin/settings')
            ->assertOk()
            ->assertJsonPath('settings.mail.mailer', 'log')
            ->assertJsonPath('notifications_configured', false);
    }

    /**
     * Saving one setting must not blank the other: the form submits both, but a
     * partial update should behave predictably.
     */
    public function test_updating_only_the_toggle_leaves_the_recipients_alone(): void
    {
        $this->admin();
        $this->settings()->set(SettingService::ORDER_NOTIFICATION_EMAILS, 'owner@chama.ma');

        $this->putJson('/api/admin/settings', [
            'notifications' => ['order_enabled' => true],
        ])->assertOk();

        $this->assertSame('owner@chama.ma', (string) $this->settings()->get(SettingService::ORDER_NOTIFICATION_EMAILS));
    }

    // ---------------------------------------------------------------
    // Customer email at checkout
    // ---------------------------------------------------------------

    public function test_a_customer_email_is_stored_when_given(): void
    {
        $this->enableAlerts('owner@chama.ma');
        Mail::fake();

        $this->checkout(['email' => 'ahmed@example.com'])->assertCreated();

        $this->assertSame('ahmed@example.com', Order::query()->firstOrFail()->email);
    }

    public function test_an_omitted_customer_email_stores_null(): void
    {
        $this->checkout()->assertCreated();

        $this->assertNull(Order::query()->firstOrFail()->email);
    }

    /**
     * An empty string and an absent field must land in the same place. They are
     * the same shopper behaviour, and an order email of "" is a stored value
     * that anything comparing emptiness loosely will misread.
     */
    public function test_an_empty_customer_email_stores_null(): void
    {
        $this->checkout(['email' => '   '])->assertCreated();

        $this->assertNull(Order::query()->firstOrFail()->email);
    }

    // ---------------------------------------------------------------
    // The message itself
    // ---------------------------------------------------------------

    /**
     * The alert is the owner's only view of an order they have not opened yet,
     * so it has to actually contain the order. A Blade typo in a mail view
     * fails silently: the mail still sends, it just says nothing.
     */
    public function test_the_message_carries_the_order_details(): void
    {
        $order = $this->placeOrder([
            'email' => 'ahmed@example.com',
            'notes' => 'Appeler apres 18h',
        ]);

        $mail = new NewOrderNotification($order);
        $html = $mail->render();

        foreach ([
            $order->reference,
            $order->customer_name,
            $order->phone,
            'ahmed@example.com',
            'CASABLANCA',
            'Appeler apres 18h',
            'Oud Royale',
            $order->items->first()->product_sku,
        ] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }

        // The number the owner has to reconcile against a bank transfer.
        $this->assertStringContainsString(
            number_format((float) $order->total, 2, '.', ' '),
            $html
        );
    }

    /**
     * The alert has to be actionable without leaving the inbox, and the link
     * must point at the panel rather than the storefront.
     */
    public function test_the_message_links_to_the_admin_panel(): void
    {
        config(['app.url' => 'https://chamaperfumes.ma/']);

        $order = $this->placeOrder();

        $html = (new NewOrderNotification($order))->render();

        $this->assertStringContainsString('https://chamaperfumes.ma/fr/admin/orders', $html);
    }

    /**
     * A malformed address is a typo, not a reason to lose the order — but it
     * must not be stored either.
     */
    public function test_a_malformed_customer_email_is_rejected(): void
    {
        $this->checkout(['email' => 'not-an-email'])->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertSame(0, Order::query()->count());
    }
}
