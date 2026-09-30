<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OrderStatusRequest;
use App\Http\Requests\Admin\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;

/**
 * Orders, read from the storefront and written by hand.
 *
 * Orders arrive two ways: through checkout, and through the owner typing in a
 * customer who called or walked in. The second way is not a lesser record — it
 * is the same order, so it is created through the same OrderService and lands
 * in the same table with the same columns. That is the point: the owner does not
 * end up with two kinds of order to remember.
 */
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

    /**
     * Record an order taken over the phone or at the counter.
     *
     * No order email is sent, and that is a real difference from checkout
     * rather than an oversight. The alert exists to tell the owner something
     * happened while they were elsewhere; here they are the one who happened,
     * and a "new order" mail arriving seconds after they typed it reads as an
     * order they did not take.
     */
    public function store(StoreOrderRequest $request, OrderService $orders): JsonResponse
    {
        $data = $request->validated();

        // `place()` re-reads every price from the database and locks the rows
        // it touches, so what is typed into the panel cannot set its own totals
        // and cannot oversell stock. The request is trusted for who the customer
        // is, and for nothing about what things cost or whether they are there.
        try {
            $order = $orders->place(
                $data['items'],
                $data,
                $data['locale'] ?? (string) App::getLocale(),
            );
        } catch (\DomainException $e) {
            // Sold out, deactivated, or no longer enough stock: a fact about
            // the catalogue that the owner needs to read, not a crash.
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('admin_order_create_failed', ['message' => $e->getMessage()]);

            return response()->json(['message' => __('api.errors.order_create_failed')], 500);
        }

        // Cash collected, or a transfer already seen: worth recording at the
        // moment of writing the order, because there is no later prompt to
        // remember it by. Anything else is left to the status flow.
        if (($data['payment_status'] ?? null) === 'paid') {
            $order->update(['payment_status' => 'paid']);
        }

        return response()->json(['order' => new OrderResource($order->fresh('items'))], 201);
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
