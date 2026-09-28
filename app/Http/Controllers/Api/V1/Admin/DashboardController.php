<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\App;

class DashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $since = now()->subDays(30);

        $orders = Order::query();
        $delivered = (clone $orders)->where('status', OrderStatus::Delivered)->get();

        return response()->json([
            'cards' => [
                [
                    'key' => 'revenue',
                    'value' => (float) $delivered->sum('total'),
                    'currency' => config('chamma.currency.code'),
                ],
                [
                    'key' => 'orders',
                    'value' => (clone $orders)->count(),
                ],
                [
                    'key' => 'pending',
                    'value' => (clone $orders)->whereIn('status', [OrderStatus::Pending->value, OrderStatus::Confirmed->value, OrderStatus::Preparing->value])->count(),
                ],
                [
                    'key' => 'products',
                    'value' => Product::query()->count(),
                ],
                [
                    // 1..3 only: sold out items are counted separately so the
                    // two numbers never overlap and double-report a product.
                    'key' => 'low_stock',
                    'value' => Product::query()->whereBetween('stock', [1, 3])->count(),
                ],
                [
                    'key' => 'out_of_stock',
                    'value' => Product::query()->where('stock', 0)->count(),
                ],
                [
                    'key' => 'brands',
                    'value' => Brand::query()->count(),
                ],
                [
                    'key' => 'categories',
                    'value' => Category::query()->count(),
                ],
            ],
            'recent_orders' => Order::query()
                ->with('items')
                ->latest()
                ->take(8)
                ->get()
                ->map(fn (Order $order) => [
                    'id' => $order->id,
                    'reference' => $order->reference,
                    'customer_name' => $order->customer_name,
                    'city' => $order->city,
                    'total' => (float) $order->total,
                    'status' => $order->status->value,
                    // Labelled in the admin's language; `locale` tells them which
                    // language the customer ordered in.
                    'status_label' => $order->status->label(),
                    'order_locale' => $order->locale,
                    'status_color' => $order->status->color(),
                    'items_count' => $order->items->sum('quantity'),
                    'created_at' => $order->created_at?->toIso8601String(),
                ]),
            'low_stock_products' => Product::query()
                ->with('translations', 'images')
                ->where('stock', '<=', 3)
                ->orderBy('stock')
                ->orderBy('id')
                ->take(8)
                ->get()
                ->map(fn (Product $product) => [
                    'id' => $product->id,
                    'name' => $product->name(),
                    'sku' => $product->sku,
                    'stock' => $product->stock,
                    'image' => $product->primaryImage()?->url,
                ]),
        ]);
    }
}
