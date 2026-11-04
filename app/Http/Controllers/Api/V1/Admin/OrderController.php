<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OrderStatusRequest;
use App\Http\Requests\Admin\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\ShippingFeeService;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
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
        $validated = $request->validate([
            'status' => ['nullable', 'string'],
            'search' => ['nullable', 'string', 'max:120'],
            // The window the owner is looking at. A date on its own means the
            // whole of that day: "from the 1st" is the 1st, not midnight on
            // the 1st with every order of the morning missing.
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
        ]);

        $query = $this->filtered($validated)->with('items')->latest();

        $page = $query->paginate((int) config('chamma.catalog.admin_per_page'))->withQueryString();

        return OrderResource::collection($page)->additional([
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
            // What the filtered orders came to. Over the whole filtered set
            // and not just this page: the owner is asking what the shop took
            // and what it earned, and a page of twenty would answer a
            // different question.
            'summary' => $this->summary($this->filtered($validated)),
        ]);
    }

    /**
     * The orders the request is asking for.
     *
     * @param  array<string, mixed>  $filters
     */
    private function filtered(array $filters): Builder
    {
        $query = Order::query();

        if ($status = $filters['status'] ?? null) {
            if (in_array($status, OrderStatus::values(), true)) {
                $query->where('status', $status);
            }
        }

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $like = '%'.$search.'%';

            // The number is compared the way it is written and the way it is
            // normally written. An order recorded in the panel carries the
            // canonical form ("0612345678"), so a search typed the way people
            // say it ("612345678", or even "0612") has to be reduced the same
            // way before it is compared — otherwise the owner would type a
            // number they know and be told they have no orders.
            $digits = PhoneNumber::searchDigits($search);

            $query->where(fn ($q) => $q
                ->where('reference', 'like', $like)
                ->orWhere('customer_name', 'like', $like)
                ->orWhere('phone', 'like', $like)
                ->orWhere('city', 'like', $like)
                ->when($digits !== [], fn ($q) => $q->orWhere('phone', 'like', '%'.$digits[0].'%'))
            );
        }

        if ($start = $filters['start_date'] ?? null) {
            $query->whereDate('created_at', '>=', Carbon::parse($start)->startOfDay());
        }

        if ($end = $filters['end_date'] ?? null) {
            $query->whereDate('created_at', '<=', Carbon::parse($end)->endOfDay());
        }

        return $query;
    }

    /**
     * What the filtered orders came to.
     *
     * The **benefit** is the turnover minus what the goods cost: the order's
     * total minus the purchase price of what is in it, read from the line's
     * own snapshot rather than from the catalogue — so the benefit of an
     * order is the benefit it had when it was placed.
     */
    private function summary(Builder $query): array
    {
        $orders = (clone $query)->count();
        $sales = (float) (clone $query)->sum('total');

        $cost = (float) (clone $query)
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->sum(DB::raw('order_items.unit_cost * order_items.quantity'));

        return [
            'orders' => $orders,
            'total_sales' => round($sales, 2),
            'total_cost' => round($cost, 2),
            'total_benefit' => round($sales - $cost, 2),
        ];
    }

    /**
     * Record an order taken over the phone or at the counter.
     *
     * It is created as **delivered**, and that is the whole point of writing
     * it here. The owner is recording a conversation they just had: the
     * customer called, the parcel went out, the money changed hands. An order
     * that landed as "new" would sit in the list waiting for somebody to
     * remember to move it along — the very chore the panel exists to remove.
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
        // What the owner decided the delivery costs. Null means "the carrier's
        // price for the city", which is what the panel fills in; a number
        // means the owner has decided otherwise — a negotiated rate, or a free
        // delivery.
        $shippingCost = array_key_exists('shipping_cost', $data) && $data['shipping_cost'] !== null
            ? (float) $data['shipping_cost']
            : null;

        try {
            $order = $orders->place(
                $data['items'],
                $data,
                $data['locale'] ?? (string) App::getLocale(),
                OrderStatus::Delivered,
                $shippingCost,
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
        // remember it by. A delivered order is collected on delivery, the same
        // rule that applies when one is moved to delivered from the list — so
        // the default here is paid, and the owner only has to say otherwise
        // for a transfer that has not arrived yet.
        $order->update([
            'payment_status' => ($data['payment_status'] ?? 'paid') === 'pending' ? 'pending' : 'paid',
        ]);

        // **The fee is kept for that city.** The owner priced the delivery
        // for Casablanca, so the next order to Casablanca is billed the same
        // way — and so is the storefront, which has to quote the same price
        // the panel charges.
        //
        // Only when they asked for it: a free delivery for one order is not
        // a decision about the whole city, and the panel says so.
        if ($shippingCost !== null && ($data['remember_fee'] ?? true) && ! empty($data['city'])) {
            app(ShippingFeeService::class)->set((string) $data['city'], $shippingCost);
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
