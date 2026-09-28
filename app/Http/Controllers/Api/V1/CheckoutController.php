<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCheckoutRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\ShippingService;
use App\Services\ShippingZoneService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly ShippingService $shipping,
        private readonly ShippingZoneService $zones,
        private readonly PaymentService $payments,
    ) {}

    /**
     * Guest checkout, paid on delivery or by bank transfer.
     */
    public function store(StoreCheckoutRequest $request): JsonResponse
    {
        $locale = $request->input('locale') ?: App::getLocale();

        $customer = $request->safe()->only(['name', 'phone', 'city', 'address', 'notes']);
        $customer['payment_method'] = $request->input('payment_method') ?: $this->payments->default();

        try {
            $order = $this->orders->place(
                $request->validated()['items'],
                $customer,
                $locale,
            );
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('checkout_failed', ['message' => $e->getMessage()]);

            return response()->json(['message' => __('api.errors.checkout_failed')], 500);
        }

        return response()->json([
            'order' => new OrderResource($order),
            'message' => __('api.store.order_confirmed', ['reference' => $order->reference], $locale),
        ], 201);
    }

    /**
     * Order lookup for the confirmation screen: reference + phone.
     */
    public function show(Request $request, string $reference): JsonResponse
    {
        $phone = (string) $request->query('phone', '');

        $order = Order::query()
            ->with('items')
            ->where('reference', $reference)
            ->when($phone !== '', fn ($q) => $q->where('phone', $phone))
            ->first();

        if (! $order) {
            return response()->json(['message' => __('api.errors.not_found')], 404);
        }

        return response()->json(['order' => new OrderResource($order)]);
    }

    /**
     * Everything the checkout form needs to render itself: the deliverable
     * cities with their carrier fee, the payment methods, and the delivery
     * promise. Fetched once, so the page needs no hardcoded list.
     */
    public function options(Request $request): JsonResponse
    {
        $locale = App::getLocale();

        return response()->json([
            'cities' => $this->zones->options($locale),
            'payment_methods' => $this->payments->methods($locale),
            'payment' => [
                'default' => $this->payments->default(),
                'bank' => config('chamma.payment.bank'),
                'whatsapp' => $this->payments->whatsapp(),
                'whatsapp_url' => $this->payments->whatsappUrl(''),
            ],
            'shipping' => [
                'free_threshold' => $this->shipping->freeThreshold(),
                'estimate' => $this->shipping->estimate($locale),
            ],
        ]);
    }

    /**
     * Live delivery quote so the shopper sees the real total before submitting.
     *
     * The subtotal is recomputed from the cart against current prices; a
     * client-supplied `subtotal` is ignored on purpose so the free-shipping
     * threshold cannot be gamed.
     */
    public function quote(Request $request): JsonResponse
    {
        $lines = (array) $request->input('items', []);
        $city = (string) $request->input('city', '');

        $subtotal = $this->orders->subtotal($lines);
        $shipping = $this->shipping->cost($subtotal, $city);

        return response()->json([
            'subtotal' => round($subtotal, 2),
            'shipping_cost' => $shipping,
            'total' => round($subtotal + $shipping, 2),
            'currency' => config('chamma.currency.code'),
            'is_free_shipping' => $this->shipping->isFree($subtotal),
            'remaining_for_free_shipping' => $this->shipping->remainingForFreeShipping($subtotal),
            'free_threshold' => $this->shipping->freeThreshold(),
        ]);
    }
}
