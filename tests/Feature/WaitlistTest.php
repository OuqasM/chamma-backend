<?php

namespace Tests\Feature;

use App\Mail\ProductRestockedNotification;
use App\Models\Product;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\SettingService;
use App\Services\WaitlistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The back-in-stock waiting list.
 *
 * The customer's side is small: type a phone number on a product that is not
 * available, and be told it was received. The owner's side is where the
 * behaviour that matters lives, and most of these tests are about the two ways
 * it can go wrong: the same person ending up on the calling list twice, and the
 * owner not being told when the product comes back.
 */
class WaitlistTest extends TestCase
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

    /**
     * A product that exists and is available, as the admin panel would leave it.
     */
    private function product(array $overrides = []): Product
    {
        $this->admin();

        $this->postJson('/api/admin/products', array_merge([
            'name' => 'Oud Royale',
            'price' => 890,
            'stock' => 10,
        ], $overrides))->assertCreated();

        return Product::query()->latest('id')->firstOrFail();
    }

    /**
     * The same product, but labelled out of stock, which is when the waiting
     * list form is actually on the page.
     */
    private function soldOutProduct(array $overrides = []): Product
    {
        $product = $this->product($overrides);

        $this->patchJson("/api/admin/products/{$product->id}/availability")->assertOk();

        return $product->fresh();
    }

    private function join(
        Product $product,
        string $phone = '0612345678',
        string $locale = 'fr',
        string $name = 'Amina Benali',
    ) {
        return $this->postJson("/api/{$locale}/products/{$product->slug}/waitlist", [
            'name' => $name,
            'phone' => $phone,
        ]);
    }

    /**
     * The locale is the URL prefix, so the Arabic case posts to /api/ar and
     * expects the entry to be labelled Arabic without anything in the body.
     */
    private function joinArabic(Product $product, string $phone, string $name = 'أمينة بنعلي'): TestResponse
    {
        return $this->postJson("/api/ar/products/{$product->slug}/waitlist", [
            'name' => $name,
            'phone' => $phone,
        ]);
    }

    // ---------------------------------------------------------------
    // The customer signs up
    // ---------------------------------------------------------------

    public function test_a_customer_can_sign_up_for_a_phone_number(): void
    {
        $product = $this->soldOutProduct();

        $this->join($product)
            ->assertCreated()
            ->assertJsonPath('waitlist.product_id', $product->id)
            ->assertJsonPath('waitlist.created', true);

        $this->assertDatabaseHas('waitlist_entries', [
            'product_id' => $product->id,
            'phone' => '0612345678',
            'phone_normalised' => '+212612345678',
        ]);
    }

    /**
     * The form is only on a page where the product cannot be bought, so a
     * signup for an available product means a stale page or a scripted caller.
     * Either way, the customer would be promised a notification about something
     * that is already for sale.
     */
    public function test_a_product_that_is_already_available_rejects_signups(): void
    {
        $product = $this->product();

        $this->join($product)->assertNotFound();

        $this->assertDatabaseCount('waitlist_entries', 0);
    }

    public function test_an_unknown_product_slug_rejects_signups(): void
    {
        $this->postJson('/api/fr/products/nothing-here/waitlist', [
            'name' => 'Amina Benali',
            'phone' => '0612345678',
        ])->assertNotFound();

        $this->assertDatabaseCount('waitlist_entries', 0);
    }

    public function test_the_phone_is_required(): void
    {
        $product = $this->soldOutProduct();

        // A name and no number: the phone is the field under test, so sending
        // nothing at all would fail on two rules and prove only one.
        $this->postJson("/api/fr/products/{$product->slug}/waitlist", ['name' => 'Amina Benali'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');

        $this->assertDatabaseCount('waitlist_entries', 0);
    }

    /**
     * The name is required, not optional. A bare number on the waiting list is
     * a dial string: the owner who rings it mid-shift cannot tell a regular
     * from somebody who called once, and cannot say who is on the line.
     */
    public function test_the_name_is_required(): void
    {
        $product = $this->soldOutProduct();

        $this->postJson("/api/fr/products/{$product->slug}/waitlist", ['phone' => '0612345678'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->assertDatabaseCount('waitlist_entries', 0);
    }

    /**
     * Whitespace is not a name. " " satisfies a length check while leaving the
     * owner a blank column and no way to greet the caller.
     */
    public function test_a_blank_name_is_rejected(): void
    {
        $product = $this->soldOutProduct();

        $this->postJson("/api/fr/products/{$product->slug}/waitlist", [
            'name' => '   ',
            'phone' => '0612345678',
        ])->assertUnprocessable()->assertJsonValidationErrors('name');

        $this->assertDatabaseCount('waitlist_entries', 0);
    }

    public function test_the_name_is_trimmed_before_it_is_stored(): void
    {
        $product = $this->soldOutProduct();

        $this->join($product, '0612345678', 'fr', '  Amina Benali  ')->assertCreated();

        $this->assertDatabaseHas('waitlist_entries', [
            'phone_normalised' => '+212612345678',
            'name' => 'Amina Benali',
        ]);
    }

    /**
     * A number that cannot receive an SMS is not a phone number, whatever the
     * digits look like.
     */
    public function test_an_invalid_phone_is_rejected(): void
    {
        $product = $this->soldOutProduct();

        $this->join($product, '12345')->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->join($product, '0498765432')->assertUnprocessable()->assertJsonValidationErrors('phone');

        $this->assertDatabaseCount('waitlist_entries', 0);
    }

    /**
     * The locale is stored so the store can reply in the language the customer
     * was browsing in.
     */
    public function test_the_signup_records_the_locale(): void
    {
        $product = $this->soldOutProduct();

        $this->joinArabic($product, '0612345678')->assertCreated();

        $this->assertDatabaseHas('waitlist_entries', [
            'product_id' => $product->id,
            'locale' => 'ar',
        ]);
    }

    /**
     * The language is taken from the URL, not the body. A body field for it
     * would be a second source for the same fact, and a spoofable one.
     */
    public function test_the_locale_cannot_be_overridden_from_the_body(): void
    {
        $product = $this->soldOutProduct();

        $this->postJson("/api/ar/products/{$product->slug}/waitlist", [
            'name' => 'أمينة بنعلي',
            'phone' => '0612345678',
            'locale' => 'en',
        ])->assertCreated();

        $this->assertDatabaseHas('waitlist_entries', [
            'product_id' => $product->id,
            'locale' => 'ar',
        ]);
    }

    // ---------------------------------------------------------------
    // One person, one entry
    // ---------------------------------------------------------------

    /**
     * The whole reason the table has a normalised column and a unique index:
     * a customer who signs up twice must not become two calls to make.
     */
    public function test_signing_up_twice_keeps_one_entry(): void
    {
        $product = $this->soldOutProduct();

        $this->join($product)->assertCreated();
        $this->join($product)->assertOk()->assertJsonPath('waitlist.created', false);

        $this->assertDatabaseCount('waitlist_entries', 1);
    }

    /**
     * The same handset typed four ways. Somebody retyping their number because
     * the form said "invalid" must not end up on the list four times.
     */
    public function test_the_same_number_in_a_different_format_is_one_entry(): void
    {
        $product = $this->soldOutProduct();

        foreach (['0612345678', '06 12 34 56 78', '+212612345678', '00212612345678'] as $phone) {
            $this->join($product, $phone)->assertSuccessful();
        }

        $this->assertDatabaseCount('waitlist_entries', 1);
        $this->assertDatabaseHas('waitlist_entries', ['phone_normalised' => '+212612345678']);
    }

    /**
     * The second attempt is the one that wins. Someone who mistyped their number
     * and then corrected it must end up with the corrected one, or the store
     * would call a number that does not exist.
     */
    public function test_a_second_signup_updates_the_stored_number(): void
    {
        $product = $this->soldOutProduct();

        $this->join($product, '0612 34 56 78')->assertCreated();
        $this->join($product, '0612345678')->assertOk();

        $this->assertDatabaseHas('waitlist_entries', [
            'product_id' => $product->id,
            'phone' => '0612345678',
        ]);
    }

    /**
     * Two people, one product, two entries. The unique index is per product,
     * not global: a shopper who wants two perfumes wants two calls.
     */
    public function test_the_same_person_can_wait_for_two_products(): void
    {
        $first = $this->soldOutProduct(['name' => 'Oud Royale']);
        $second = $this->soldOutProduct(['name' => 'Ambre Nocturne']);

        $this->join($first, '0612345678')->assertCreated();
        $this->join($second, '0612345678')->assertCreated();

        $this->assertDatabaseCount('waitlist_entries', 2);
    }

    /**
     * Someone who asked, was called, and then asked again is outstanding again.
     * The owner needs to know they came back, not have their old "done" row sit
     * there hiding the new request.
     */
    public function test_signing_up_again_after_being_contacted_puts_them_back_on_the_list(): void
    {
        $product = $this->soldOutProduct();
        $this->join($product)->assertCreated();

        $this->admin();
        $this->patchJson('/api/admin/waitlist/notified', ['ids' => [WaitlistEntry::query()->value('id')]])
            ->assertOk()
            ->assertJsonPath('marked', 1);

        $this->assertSame(0, app(WaitlistService::class)->pendingCount());

        // Back in stock, then out again: the product started unavailable, so
        // the first toggle makes it available and only the second takes it off
        // sale. The customer then comes back to the page.
        $this->patchJson("/api/admin/products/{$product->id}/availability")->assertOk();
        $this->patchJson("/api/admin/products/{$product->id}/availability")->assertOk();
        $this->assertFalse($product->fresh()->in_stock);

        // 200, not 201: this is the same row coming back, which is the merge
        // rule doing its job. The outcome that matters is the one below it.
        $this->join($product->fresh())->assertOk()->assertJsonPath('waitlist.created', false);

        $this->assertNull(WaitlistEntry::query()->first()->notified_at);
        $this->assertSame(1, app(WaitlistService::class)->pendingCount());
    }

    /**
     * Two requests for the same number at the same moment. Both read "no such
     * entry", both try to insert, and the unique index rejects the second — so
     * the loser has to retry as an update rather than surfacing a 500 to a
     * customer who simply tapped the button twice.
     *
     * The race is staged rather than hoped for: a listener on the model's save
     * event writes the competing row at the exact moment the service believes
     * it is the only one inserting. That is the state the losing request really
     * finds itself in, and the only way to reach the recovery branch from a
     * single process.
     */
    public function test_a_concurrent_double_signup_merges_instead_of_erroring(): void
    {
        $product = $this->soldOutProduct();
        $normalised = '+212612345678';
        $staged = false;

        WaitlistEntry::saving(function () use ($product, $normalised, &$staged) {
            if ($staged) {
                return;
            }

            $staged = true;

            // The other request wins the race by a hair.
            DB::table('waitlist_entries')->insert([
                'product_id' => $product->id,
                'name' => 'Amina Benali',
                'phone' => '0612345678',
                'phone_normalised' => $normalised,
                'locale' => 'fr',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->join($product, '06 12 34 56 78')
            ->assertOk()
            ->assertJsonPath('waitlist.created', false);

        $this->assertTrue($staged, 'the race was never staged');
        $this->assertDatabaseCount('waitlist_entries', 1);

        // The retry updated the winning row rather than adding a second.
        $this->assertDatabaseHas('waitlist_entries', [
            'phone' => '06 12 34 56 78',
            'phone_normalised' => $normalised,
        ]);
    }

    /**
     * A real double-insert, straight at the service, to prove the recovery
     * branch above is reachable and does not swallow genuine database faults.
     */
    public function test_the_merge_survives_the_unique_index(): void
    {
        $product = $this->soldOutProduct();
        $service = app(WaitlistService::class);

        $first = $service->addFromAdmin($product, 'Amina Benali', '0612345678');
        $this->assertTrue($first['created']);

        // The number the customer would have typed a second time, reformatted.
        $second = $service->addFromAdmin($product, 'Amina Benali', '+212 612 34 56 78');
        $this->assertFalse($second['created']);

        $this->assertSame($first['entry']->id, $second['entry']->id);
        $this->assertDatabaseCount('waitlist_entries', 1);
    }

    // ---------------------------------------------------------------
    // Rate limiting
    // ---------------------------------------------------------------

    /**
     * This endpoint stores a number a person gave us and is not authenticated
     * by anything, so it has to be the one endpoint on the storefront that
     * cannot be hammered.
     */
    public function test_signups_are_rate_limited(): void
    {
        $product = $this->soldOutProduct();
        $slug = $product->slug;

        for ($i = 0; $i < 5; $i++) {
            $this->join($product, '061234567'.($i % 10))->assertSuccessful();
        }

        $this->postJson("/api/fr/products/{$slug}/waitlist", [
            'name' => 'Amina Benali',
            'phone' => '0655555555',
        ])->assertStatus(429);
    }

    // ---------------------------------------------------------------
    // The owner sees the list
    // ---------------------------------------------------------------

    public function test_the_admin_panel_lists_outstanding_entries(): void
    {
        $product = $this->soldOutProduct();
        $this->join($product)->assertCreated();

        $this->admin();
        $this->getJson('/api/admin/waitlist')
            ->assertOk()
            ->assertJsonCount(1, 'waitlist')
            ->assertJsonPath('waitlist.0.phone', '0612345678')
            ->assertJsonPath('waitlist.0.notified_at', null)
            ->assertJsonPath('summary.pending_total', 1);
    }

    /**
     * A Moroccan store calling a Moroccan number opens WhatsApp, so the panel
     * hands over a link rather than making the owner retype digits.
     */
    public function test_each_entry_carries_a_whatsapp_link(): void
    {
        $product = $this->soldOutProduct();
        $this->join($product, '0612345678')->assertCreated();

        $this->admin();
        $this->getJson('/api/admin/waitlist')
            ->assertOk()
            ->assertJsonPath('waitlist.0.whatsapp_url', 'https://wa.me/212612345678');
    }

    public function test_the_admin_panel_requires_authentication(): void
    {
        $this->getJson('/api/admin/waitlist')->assertUnauthorized();
    }

    public function test_a_non_admin_cannot_read_the_waiting_list(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_admin' => false]));

        $this->getJson('/api/admin/waitlist')->assertForbidden();
    }

    public function test_entries_can_be_filtered_by_product(): void
    {
        $first = $this->soldOutProduct(['name' => 'Oud Royale']);
        $second = $this->soldOutProduct(['name' => 'Ambre Nocturne']);

        $this->join($first, '0612345678')->assertCreated();
        $this->join($second, '0699999999')->assertCreated();

        $this->admin();
        $this->getJson("/api/admin/waitlist?product={$second->id}")
            ->assertOk()
            ->assertJsonCount(1, 'waitlist')
            ->assertJsonPath('waitlist.0.phone', '0699999999');
    }

    /**
     * The outstanding list is the working view; contacted ones move out of it
     * but are still on record.
     */
    public function test_entries_can_be_filtered_by_status(): void
    {
        $product = $this->soldOutProduct();
        $this->join($product, '0612345678')->assertCreated();
        $this->join($product, '0699999999')->assertCreated();

        $this->admin();
        $this->patchJson('/api/admin/waitlist/notified', [
            'ids' => [WaitlistEntry::query()->where('phone', '0612345678')->value('id')],
        ])->assertOk();

        $this->getJson('/api/admin/waitlist')
            ->assertOk()
            ->assertJsonCount(1, 'waitlist')
            ->assertJsonPath('waitlist.0.phone', '0699999999');

        $this->getJson('/api/admin/waitlist?status=notified')
            ->assertOk()
            ->assertJsonCount(1, 'waitlist')
            ->assertJsonPath('waitlist.0.phone', '0612345678');
    }

    // ---------------------------------------------------------------
    // Marking as called
    // ---------------------------------------------------------------

    public function test_entries_can_be_marked_as_contacted(): void
    {
        $product = $this->soldOutProduct();
        $this->join($product, '0612345678')->assertCreated();
        $this->join($product, '0699999999')->assertCreated();

        $this->admin();
        $ids = WaitlistEntry::query()->pluck('id')->all();

        $this->patchJson('/api/admin/waitlist/notified', ['ids' => $ids])
            ->assertOk()
            ->assertJsonPath('marked', 2);

        $this->assertSame(0, app(WaitlistService::class)->pendingCount());
        $this->assertSame(2, WaitlistEntry::query()->notified()->count());
    }

    /**
     * An owner who worked down a page of numbers should clear them in one
     * action rather than ten round trips.
     */
    public function test_a_whole_filtered_list_can_be_marked_at_once(): void
    {
        $product = $this->soldOutProduct();
        $this->join($product, '0612345678')->assertCreated();
        $this->join($product, '0699999999')->assertCreated();

        $this->admin();
        $this->patchJson('/api/admin/waitlist/notified', ['product' => $product->id])
            ->assertOk()
            ->assertJsonPath('marked', 2);
    }

    /**
     * "Mark everything" is not a request a mis-click should be able to perform
     * on the whole shop. Naming the entries, or naming a product, is required.
     */
    public function test_marking_everything_in_the_shop_is_refused(): void
    {
        $product = $this->soldOutProduct();
        $this->join($product)->assertCreated();

        $this->admin();
        $this->patchJson('/api/admin/waitlist/notified', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ids');

        $this->assertSame(1, app(WaitlistService::class)->pendingCount());
    }

    public function test_marking_contacted_requires_authentication(): void
    {
        $this->patchJson('/api/admin/waitlist/notified', ['ids' => [1]])->assertUnauthorized();
    }

    // ---------------------------------------------------------------
    // A number taken over the phone
    // ---------------------------------------------------------------

    /**
     * Waiting lists start in the shop too. Going through the same service means
     * a number written down and the same number typed online merge.
     */
    public function test_the_admin_can_add_a_number_taken_over_the_phone(): void
    {
        $product = $this->soldOutProduct();

        $this->admin();
        $this->postJson('/api/admin/waitlist', [
            'product_id' => $product->id,
            'name' => 'Amina Benali',
            'phone' => '0612345678',
        ])->assertCreated()->assertJsonPath('entry.created', true);

        $this->assertDatabaseHas('waitlist_entries', [
            'product_id' => $product->id,
            'phone' => '0612345678',
        ]);
    }

    public function test_an_admin_added_number_merges_with_a_signup(): void
    {
        $product = $this->soldOutProduct();
        $this->join($product, '06 12 34 56 78')->assertCreated();

        $this->admin();
        $this->postJson('/api/admin/waitlist', [
            'product_id' => $product->id,
            'name' => 'Amina Benali',
            'phone' => '0612345678',
        ])->assertOk()->assertJsonPath('entry.created', false);

        $this->assertDatabaseCount('waitlist_entries', 1);
    }

    public function test_the_admin_rejects_an_invalid_number(): void
    {
        $product = $this->soldOutProduct();

        $this->admin();
        $this->postJson('/api/admin/waitlist', [
            'product_id' => $product->id,
            'name' => 'Amina Benali',
            'phone' => '12345',
        ])->assertUnprocessable()->assertJsonValidationErrors('phone');
    }

    // ---------------------------------------------------------------
    // The owner is told when the product is back
    // ---------------------------------------------------------------

    public function test_the_owner_is_emailed_when_the_product_becomes_available_again(): void
    {
        Mail::fake();
        $this->enableAlerts('one@chama.ma');

        $product = $this->soldOutProduct();
        $this->join($product, '0612345678')->assertCreated();
        $this->join($product, '0699999999')->assertCreated();

        $this->patchJson("/api/admin/products/{$product->id}/availability")->assertOk();

        Mail::assertSent(ProductRestockedNotification::class, function ($mail) use ($product) {
            return $mail->hasTo('one@chama.ma')
                && $mail->product->is($product)
                && $mail->entries->count() === 2;
        });
    }

    /**
     * Sending the same numbers again every time the product is edited would
     * train the owner to ignore the alert. Only the unavailable -> available
     * edge counts.
     */
    public function test_editing_an_available_product_does_not_resend_the_alert(): void
    {
        Mail::fake();
        $this->enableAlerts('one@chama.ma');

        $product = $this->soldOutProduct();
        $this->join($product)->assertCreated();

        $this->patchJson("/api/admin/products/{$product->id}/availability")->assertOk();
        Mail::assertSentCount(1);

        // Re-saving an already available product: a price change, a description.
        $this->putJson("/api/admin/products/{$product->id}", [
            'name' => 'Oud Royale',
            'price' => 950,
            'stock' => 10,
        ])->assertOk();

        Mail::assertSentCount(1);
    }

    /**
     * Switching something off that nobody is waiting on must not produce an
     * email to the owner about nobody.
     */
    public function test_no_alert_when_nobody_is_waiting(): void
    {
        Mail::fake();
        $this->enableAlerts('one@chama.ma');

        $product = $this->soldOutProduct();

        $this->patchJson("/api/admin/products/{$product->id}/availability")->assertOk();

        Mail::assertNothingSent();
    }

    public function test_no_alert_when_alerts_are_switched_off(): void
    {
        Mail::fake();

        $product = $this->soldOutProduct();
        $this->join($product)->assertCreated();

        $this->patchJson("/api/admin/products/{$product->id}/availability")->assertOk();

        Mail::assertNothingSent();
        $this->assertSame(1, app(WaitlistService::class)->pendingCount());
    }

    /**
     * The alert is a prompt, not a receipt. Nobody has picked up the phone yet,
     * so the entries stay outstanding and are not quietly retired.
     */
    public function test_an_alert_does_not_mark_entries_as_contacted(): void
    {
        Mail::fake();
        $this->enableAlerts('one@chama.ma');

        $product = $this->soldOutProduct();
        $this->join($product)->assertCreated();

        $this->patchJson("/api/admin/products/{$product->id}/availability")->assertOk();

        Mail::assertSent(ProductRestockedNotification::class);
        $this->assertSame(1, app(WaitlistService::class)->pendingCount());
        $this->assertSame(0, WaitlistEntry::query()->notified()->count());
    }

    /**
     * The alert is meant to make the owner call. That is only true if they can
     * see who was waiting, so the notification carries the whole outstanding
     * list and each number with a link they can open from their mail client.
     */
    public function test_the_alert_lists_every_waiting_number(): void
    {
        Mail::fake();
        $this->enableAlerts('one@chama.ma');

        $product = $this->soldOutProduct();
        $this->join($product, '0612345678')->assertCreated();
        $this->join($product, '0655555555')->assertCreated();

        $this->patchJson("/api/admin/products/{$product->id}/availability")->assertOk();

        Mail::assertSent(ProductRestockedNotification::class, function ($mail) {
            $phones = $mail->entries->pluck('phone')->all();

            return $phones === ['0655555555', '0612345678']
                && $mail->entries->every(fn ($e) => str_starts_with($e->whatsappUrl(), 'https://wa.me/'));
        });

        // Rendered straight rather than through the fake, because the fake
        // asserts the envelope and never builds the body — and the body is the
        // part the owner reads.
        $html = (new ProductRestockedNotification(
            $product->fresh(),
            app(WaitlistService::class)->pendingFor($product->fresh()),
        ))->render();

        $this->assertStringContainsString('https://wa.me/212612345678', $html);
    }

    /**
     * The hard one. An admin saving a stock change is bookkeeping; failing that
     * save because SMTP timed out would leave the storefront lying about
     * availability, which is the one thing this shop cannot get wrong.
     */
    public function test_a_broken_mailer_does_not_block_the_stock_update(): void
    {
        $this->enableAlerts('one@chama.ma');

        Mail::shouldReceive('to')
            ->andThrow(new \RuntimeException('SMTP connection refused'));

        Log::spy();

        $product = $this->soldOutProduct();
        $this->join($product, '0612345678')->assertCreated();

        $this->patchJson("/api/admin/products/{$product->id}/availability")
            ->assertOk()
            ->assertJsonPath('product.is_available', true);

        // The storefront must now be telling the truth.
        $this->assertTrue($product->fresh()->in_stock);

        // And the entry is still outstanding, so the alert can go out later.
        $this->assertSame(1, app(WaitlistService::class)->pendingCount());

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $level, array $context = []) => str_starts_with($context['restock_notification_failed'] ?? '', '')
        );
    }

    public function test_the_alert_renders_in_all_three_languages(): void
    {
        $product = $this->soldOutProduct();
        $this->join($product)->assertCreated();

        $mail = new ProductRestockedNotification($product, app(WaitlistService::class)->pendingFor($product));

        foreach (['fr', 'ar', 'en'] as $locale) {
            $this->app->setLocale($locale);
            $html = $mail->render();

            $this->assertStringContainsString('0612345678', $html, "number missing in {$locale}");
            $this->assertStringContainsString('https://wa.me/212612345678', $html, "wa.me link missing in {$locale}");
        }
    }

    /**
     * A missing translation key does not fail anything on its own — Laravel
     * hands back the key itself, so a French customer would be shown
     * "api.validation.phone_required" and an owner would be sent a subject
     * reading "mail.restock.subject". These assert the resolved strings are
     * actually prose in every language.
     */
    public function test_every_waitlist_string_is_translated(): void
    {
        $keys = [
            'api.validation.phone_required',
            'api.validation.waitlist_selection_required',
            'mail.restock.subject',
            'mail.restock.greeting',
            'mail.restock.intro',
            'mail.restock.product',
            'mail.restock.price',
            'mail.restock.waiters',
            'mail.restock.waiting_since',
            'mail.restock.call',
            'mail.restock.view',
            'mail.restock.open',
            'mail.restock.footer',
        ];

        foreach (['fr', 'ar', 'en'] as $locale) {
            $this->app->setLocale($locale);

            foreach ($keys as $key) {
                $value = __($key, ['name' => 'Oud Royale', 'count' => 2]);

                $this->assertNotSame($key, $value, "{$key} is untranslated in {$locale}");
                $this->assertNotSame('', trim($value), "{$key} is empty in {$locale}");
            }
        }
    }

    /**
     * The placeholders have to survive translation, or the owner receives a
     * subject with a literal ":name" in it.
     */
    public function test_the_restock_subject_carries_the_product_and_the_count(): void
    {
        $product = $this->soldOutProduct();
        $this->join($product)->assertCreated();
        $this->join($product, '0699999999')->assertCreated();

        $this->app->setLocale('fr');
        $mail = new ProductRestockedNotification($product, app(WaitlistService::class)->pendingFor($product));

        $this->assertSame(
            'De retour en stock : Oud Royale (2)',
            $mail->envelope()->subject,
        );
    }

    // ---------------------------------------------------------------
    // Data hygiene
    // ---------------------------------------------------------------

    /**
     * Deleting a product soft-deletes it, so its waiting list stays attached.
     *
     * That is deliberate and matches how orders behave: a product removed from
     * the shelf is not destroyed, it can be put back, and a perfume that comes
     * back has the same people waiting for it. Restoring the product therefore
     * brings the list with it, which is what this checks.
     */
    public function test_restoring_a_product_brings_back_its_waiting_list(): void
    {
        $product = $this->soldOutProduct();
        $this->join($product)->assertCreated();

        $this->admin();
        $this->deleteJson("/api/admin/products/{$product->id}")->assertOk();

        // Still there, and still counted, because the row was never destroyed.
        $this->assertDatabaseCount('waitlist_entries', 1);
        $this->assertSame(1, app(WaitlistService::class)->pendingCount());

        $this->postJson("/api/admin/products/{$product->id}/restore")->assertOk();

        $this->assertSame(1, app(WaitlistService::class)->pendingCount());
        $this->assertDatabaseHas('waitlist_entries', [
            'product_id' => $product->id,
            'phone_normalised' => '+212612345678',
        ]);
    }

    /**
     * A trashed product is not on the storefront, so it cannot be joined. The
     * entry survives for the restore, but nobody new can be added to it.
     */
    public function test_a_deleted_product_cannot_be_joined(): void
    {
        $product = $this->soldOutProduct();

        $this->admin();
        $this->deleteJson("/api/admin/products/{$product->id}")->assertOk();

        $this->join($product)->assertNotFound();

        $this->assertDatabaseCount('waitlist_entries', 0);
    }

    /**
     * A name and a phone, and nothing else.
     *
     * The name is there to greet the caller with. An email is not, and the test
     * sends one anyway to prove it is dropped rather than quietly kept: a stored
     * address would suggest a written notification that nothing sends.
     */
    public function test_only_the_name_and_the_phone_are_stored(): void
    {
        $product = $this->soldOutProduct();

        $this->postJson("/api/fr/products/{$product->slug}/waitlist", [
            'phone' => '0612345678',
            'name' => 'Ahmed Ben Ali',
            'email' => 'ahmed@example.com',
        ])->assertCreated();

        $entry = WaitlistEntry::query()->firstOrFail();

        $this->assertSame('Ahmed Ben Ali', $entry->name);

        // Sorted on both sides, because the point is which columns exist.
        // Physical column order is a detail of the migration, and asserting it
        // would fail this test whenever a column is moved for no good reason.
        $stored = array_values(array_diff(
            array_keys($entry->getAttributes()),
            ['id', 'notified_at', 'notified_channel', 'created_at', 'updated_at', 'product_id'],
        ));
        sort($stored);

        $this->assertSame(['locale', 'name', 'phone', 'phone_normalised'], $stored);
    }
}
