<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Brands come back in the order the owner chose, on every list that shows them.
 *
 * The storefront reads brands from three separate places — the home carousel,
 * `/brands`, and the `/navigation` bootstrap that feeds the mega sheet, the
 * drawer and the footer — and all three have to agree, because the owner sets one
 * `position` per brand and reasonably expects that number to mean the same thing
 * everywhere. Nothing pinned that before, so a list could quietly lose its
 * `orderBy` and the only symptom would be a menu that reads alphabetically.
 *
 * Two traps make this worth writing down rather than trusting:
 *
 *  - `position` defaults to 0, so a shop that has never touched the field has
 *    every brand tied on 0 and the visible order is entirely the tiebreaker's
 *    doing. That is the state most shops are actually in.
 *  - The assertions are on `slug`, not `name`. `name` is served out of
 *    `brand_translations`, so a brand created without a translation row answers
 *    with an empty string and an ordering test written against it passes or fails
 *    for reasons that have nothing to do with ordering.
 */
class BrandOrderingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Brands are created out of order on purpose, and with names that sort the
     * other way round from the positions, so a list that ignored `position` and
     * fell back to the name could not pass by accident.
     *
     * Each brand gets an active product because `/brands` and the home carousel
     * only list brands that have something to sell — a brand with no products is
     * absent from those two lists entirely, which would leave the assertion
     * measuring an empty array.
     *
     * Each also gets a logo, because the home tiles now drop a brand without one
     * in SQL rather than in the browser. That filter used to be the storefront's
     * job; it is the API's now, so a seed without logos measures an empty array
     * and the ordering assertion below would pass for the wrong reason.
     */
    private function seedBrands(): void
    {
        foreach ([
            ['name' => 'Zeppelin', 'position' => 1],
            ['name' => 'Aesop', 'position' => 3],
            ['name' => 'Mancora', 'position' => 2],
        ] as $brand) {
            $model = Brand::create($brand + [
                'slug' => Str::slug($brand['name']),
                'logo' => 'brands/'.Str::slug($brand['name']).'.svg',
            ]);

            Product::create([
                'brand_id' => $model->id,
                'slug' => Str::slug($brand['name']).'-eau-de-parfum',
                'sku' => strtoupper(Str::slug($brand['name'])).'-100',
                'price' => 890,
            ]);
        }
    }

    /**
     * A brand with nothing on sale, which the two product-filtered lists hide.
     */
    private function seedBrandWithoutProducts(string $name, int $position): Brand
    {
        return Brand::create(['name' => $name, 'slug' => Str::slug($name), 'position' => $position]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $payload
     * @return array<int, string>
     */
    private function slugsFrom(array $payload): array
    {
        return array_column($payload, 'slug');
    }

    public function test_the_brand_list_follows_the_position_the_owner_set(): void
    {
        $this->seedBrands();

        $slugs = $this->slugsFrom($this->getJson('/api/fr/brands')->assertOk()->json('data'));

        $this->assertSame(['zeppelin', 'mancora', 'aesop'], $slugs);
    }

    public function test_the_home_carousel_follows_the_same_order(): void
    {
        $this->seedBrands();

        $slugs = $this->slugsFrom($this->getJson('/api/fr/home')->assertOk()->json('brands'));

        $this->assertSame(['zeppelin', 'mancora', 'aesop'], $slugs);
    }

    /**
     * The mega sheet, the drawer and the footer are all built from this one
     * payload, so it is the list that decides what a shopper sees first.
     */
    public function test_the_navigation_payload_follows_the_same_order(): void
    {
        $this->seedBrands();

        $slugs = $this->slugsFrom($this->getJson('/api/fr/navigation')->assertOk()->json('brands'));

        $this->assertSame(['zeppelin', 'mancora', 'aesop'], $slugs);
    }

    public function test_the_admin_list_follows_the_same_order_as_the_shop(): void
    {
        $this->seedBrands();
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        $slugs = $this->slugsFrom($this->getJson('/api/admin/brands')->assertOk()->json('data'));

        $this->assertSame(['zeppelin', 'mancora', 'aesop'], $slugs);
    }

    public function test_brands_sharing_a_position_fall_back_to_the_name(): void
    {
        // Every brand on the column default, which is what an untouched shop
        // looks like: the name alone decides the order.
        $this->seedBrandWithoutProducts('Zeppelin', 0);
        $this->seedBrandWithoutProducts('Aesop', 0);
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        $slugs = $this->slugsFrom($this->getJson('/api/admin/brands')->assertOk()->json('data'));

        $this->assertSame(['aesop', 'zeppelin'], $slugs);
    }

    public function test_a_brand_with_nothing_to_sell_is_left_out_without_disturbing_the_rest(): void
    {
        // Position 0 would sort first, so this also proves the omission is a
        // filter and not an accident of ordering.
        $this->seedBrands();
        $this->seedBrandWithoutProducts('Empty', 0);

        $slugs = $this->slugsFrom($this->getJson('/api/fr/brands')->assertOk()->json('data'));

        $this->assertSame(['zeppelin', 'mancora', 'aesop'], $slugs);
    }

    public function test_an_inactive_brand_drops_out_of_the_shop_but_stays_in_the_admin_panel(): void
    {
        $this->seedBrands();
        Brand::where('slug', 'mancora')->update(['is_active' => false]);
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        // The panel keeps it, so the owner can switch it back on, and it keeps the
        // same position it had rather than jumping to the end.
        $admin = $this->slugsFrom($this->getJson('/api/admin/brands')->assertOk()->json('data'));
        $this->assertSame(['zeppelin', 'mancora', 'aesop'], $admin);

        $shop = $this->slugsFrom($this->getJson('/api/fr/brands')->assertOk()->json('data'));
        $this->assertSame(['zeppelin', 'aesop'], $shop);
    }
}
