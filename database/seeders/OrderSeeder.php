<?php

namespace Database\Seeders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use App\Services\ShippingService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * A handful of realistic orders so the admin dashboard, order filters and status
 * flow are all demonstrable immediately after `docker compose up -d`.
 */
class OrderSeeder extends Seeder
{
    public function __construct(private readonly ShippingService $shipping) {}

    public function run(): void
    {
        if (Order::query()->exists()) {
            return;
        }

        $products = Product::query()
            ->with('images', 'translations')
            ->where('stock', '>', 0)
            ->orderBy('id')
            ->get();

        if ($products->isEmpty()) {
            return;
        }

        $customers = [
            ['locale' => 'fr', 'first' => 'Salma', 'last' => 'Benhaddou', 'phone' => '0612345678', 'city' => 'Casablanca', 'address' => '45, rue Ibn Batouta, Appt 8', 'email' => 'salma.benhaddou@example.ma', 'status' => OrderStatus::Delivered, 'age' => 21],
            ['locale' => 'ar', 'first' => 'يوسف', 'last' => 'العلوي', 'phone' => '0661876543', 'city' => 'Marrakech', 'address' => 'شارع محمد الخامس، رقم 12، الطابق 3', 'email' => null, 'status' => OrderStatus::Confirmed, 'age' => 6],
            ['locale' => 'fr', 'first' => 'Mehdi', 'last' => 'Tazi', 'phone' => '0675123498', 'city' => 'Rabat', 'address' => '7, avenue Ibn Sina, Appt 4', 'email' => 'mehdi.tazi@example.ma', 'status' => OrderStatus::Confirmed, 'age' => 3],
            ['locale' => 'fr', 'first' => 'Nadia', 'last' => 'Cherkaoui', 'phone' => '0620987654', 'city' => 'Fès', 'address' => '33, rue de la Fontaine, Quartier Batha', 'email' => 'nadia.cherkaoui@example.ma', 'status' => OrderStatus::Confirmed, 'age' => 1],
            ['locale' => 'ar', 'first' => 'خديجة', 'last' => 'بلقاسم', 'phone' => '0655443322', 'city' => 'Agadir', 'address' => 'شارع الحسن الثاني، إقامة النخيل، رقم 5', 'email' => null, 'status' => OrderStatus::New, 'age' => 0],
            ['locale' => 'fr', 'first' => 'Anas', 'last' => 'El Fassi', 'phone' => '0644556677', 'city' => 'Oujda', 'address' => '15, avenue Hassan II', 'email' => 'anas.elfassi@example.ma', 'status' => OrderStatus::Cancelled, 'age' => 34],
        ];

        $sequence = 1042;

        foreach ($customers as $customer) {
            $lines = $this->lines($products, $sequence % 4 + 1);

            if ($lines === []) {
                continue;
            }

            $subtotal = 0.0;
            $items = [];

            foreach ($lines as $line) {
                /** @var Product $product */
                $product = $line['product'];
                $quantity = $line['quantity'];
                $lineTotal = round((float) $product->price * $quantity, 2);

                $subtotal += $lineTotal;
                $items[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name($customer['locale']),
                    'product_slug' => $product->slug($customer['locale']),
                    'product_sku' => $product->sku,
                    'product_image' => $product->primaryImage()?->path,
                    'locale' => $customer['locale'],
                    'unit_price' => $product->price,
                    'quantity' => $quantity,
                    'subtotal' => $lineTotal,
                ];
            }

            $shipping = $this->shipping->cost($subtotal, $customer['city']);

            $order = Order::query()->create([
                'reference' => 'CHM-'.$sequence,
                'locale' => $customer['locale'],
                'first_name' => $customer['first'],
                'last_name' => $customer['last'],
                'customer_name' => $customer['first'].' '.$customer['last'],
                'phone' => $customer['phone'],
                'email' => $customer['email'],
                'city' => $customer['city'],
                'address' => $customer['address'],
                'notes' => null,
                'payment_method' => 'cod',
                'payment_status' => $customer['status'] === OrderStatus::Delivered ? 'paid' : 'pending',
                'subtotal' => round($subtotal, 2),
                'shipping_cost' => $shipping,
                'total' => round($subtotal + $shipping, 2),
                'status' => $customer['status'],
                'created_at' => now()->subDays($customer['age']),
                'updated_at' => now()->subDays($customer['age']),
            ]);

            $order->items()->createMany($items);

            $sequence += 7;
        }

        $this->command?->info('  Orders: '.Order::query()->count().' demo orders created');
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return list<array{product: Product, quantity: int}>
     */
    private function lines($products, int $count): array
    {
        $lines = [];

        foreach ($products->random(min($count, $products->count())) as $product) {
            $lines[] = ['product' => $product, 'quantity' => $product->price > 900 ? 1 : random_int(1, 2)];
        }

        return $lines;
    }
}
