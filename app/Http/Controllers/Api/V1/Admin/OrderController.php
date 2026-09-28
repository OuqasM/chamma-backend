<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OrderStatusRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrderController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Order::query()->with('items')->latest();

        if ($status = $request->query('status')) {
            if (in_array($status, OrderStatus::values(), true)) {
                $query->where('status', $status);
            }
        }

        if ($search = trim((string) $request->query('search'))) {
            $like = '%'.$search.'%';
            $query->where(fn ($q) => $q
                ->where('reference', 'like', $like)
                ->orWhere('customer_name', 'like', $like)
                ->orWhere('phone', 'like', $like)
                ->orWhere('city', 'like', $like)
            );
        }

        return OrderResource::collection(
            $query->paginate((int) config('chamma.catalog.admin_per_page'))->withQueryString()
        )->additional([
            'meta' => [
                'statuses' => collect(OrderStatus::cases())->map(fn (OrderStatus $s) => [
                    'value' => $s->value,
                    'label' => $s->label(app()->getLocale()),
                    'color' => $s->color(),
                ])->values(),
                'counts' => collect(OrderStatus::cases())->mapWithKeys(
                    fn (OrderStatus $s) => [$s->value => Order::query()->where('status', $s->value)->count()]
                ),
            ],
        ]);
    }

    public function show(Order $order): JsonResponse
    {
        return response()->json(['order' => new OrderResource($order->load('items'))]);
    }

    public function updateStatus(OrderStatusRequest $request, Order $order): JsonResponse
    {
        $status = OrderStatus::from($request->validated()['status']);

        // A delivered or cancelled order is closed for business: rewinding it
        // would resurrect a collected COD payment or undo a cancellation.
        // Moving forward is always allowed (and skipping ahead is fine, since
        // admins legitimately jump straight to delivered or cancelled).
        if (! $order->status->isOpen()) {
            return response()->json([
                'message' => __('api.errors.order_closed'),
            ], 422);
        }

        $order->update(['status' => $status]);

        // Delivered orders are collected on delivery.
        if ($order->status === OrderStatus::Delivered && $order->payment_status === 'pending') {
            $order->update(['payment_status' => 'paid']);
        }

        if ($order->status === OrderStatus::Cancelled && $order->payment_status === 'paid') {
            $order->update(['payment_status' => 'refunded']);
        }

        return response()->json(['order' => new OrderResource($order->fresh('items'))]);
    }
}
