<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Services\CustomerService;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The customers, for the owner.
 *
 * Two reads and one detail:
 *
 * - `index` is the list the owner opens when they want to know who the shop
 *   sells to. It is searchable by name or by number, and it says how many
 *   orders each customer placed and when they last ordered — the two facts
 *   that tell a regular from somebody who called once.
 * - `search` is the autocomplete the new-order form uses: the customers whose
 *   number starts with what has been typed.
 * - `show` is one customer and their orders.
 *
 * A customer is written when an order is recorded, not by hand in this panel:
 * a row nobody ordered from is not a customer, it is a guess.
 */
class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));

        $query = Customer::query()
            ->withCount('orders')
            ->withMax('orders', 'created_at')
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.$search.'%';

                // The number is a **fragment**, not only a prefix: the owner
                // remembers the last digits or the middle of a number more
                // often than the whole thing. Both the digits as typed and the
                // digits with the country code — or the leading zero — taken
                // off are looked for, because the number is stored one way and
                // typed another.
                $digits = PhoneNumber::searchDigits($search);

                $q->where(function ($w) use ($like, $digits) {
                    $w->where('name', 'like', $like)
                        ->orWhere('phone', 'like', $like)
                        ->orWhere('phone_normalised', 'like', $like)
                        ->orWhere('city', 'like', $like)
                        ->orWhere('address', 'like', $like);

                    foreach ($digits as $candidate) {
                        $w->orWhere('phone_normalised', 'like', '%'.$candidate.'%');
                    }
                });
            })
            // Newest first, with the id as the tie-break so the order is
            // stable: several customers can be recorded in the same second.
            ->orderByDesc('id');

        $page = $query->paginate((int) ($validated['per_page'] ?? 20))->withQueryString();

        return response()->json([
            'customers' => collect($page->items())->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'city' => $customer->city,
                'address' => $customer->address,
                'orders_count' => (int) $customer->orders_count,
                // `withMax` names the attribute after the relation and the
                // column, so this is `orders_max_created_at` — reading it as
                // `last_order_at` silently gives every row a null date.
                'last_order_at' => $customer->orders_max_created_at
                    ? Carbon::parse($customer->orders_max_created_at)->toIso8601String()
                    : null,
                'since' => $customer->created_at?->toIso8601String(),
            ])->values(),
            'meta' => $page->toArray() + [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'summary' => $this->summary(),
        ]);
    }

    /**
     * The autocomplete the new-order form uses.
     *
     * With no term it answers with the customers the shop saw most recently,
     * so the dropdown opens with rows in it. With a term it filters them: the
     * number typed as it is spoken (a prefix) or the name.
     */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // What has been typed so far. It arrives in whatever shape it was
            // typed, and the service reduces it before it is compared.
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        $customers = app(CustomerService::class)->search(
            (string) ($validated['q'] ?? ''),
        );

        return response()->json([
            'customers' => $customers->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'city' => $customer->city,
                'address' => $customer->address,
            ])->values(),
        ]);
    }

    /**
     * One customer, and the orders they placed.
     */
    public function show(Customer $customer): JsonResponse
    {
        $orders = $customer->orders()
            ->with('items')
            ->latest()
            ->limit(50)
            ->get();

        return response()->json([
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'whatsapp_url' => $customer->whatsappUrl(),
                'city' => $customer->city,
                'address' => $customer->address,
                'orders_count' => $customer->orders()->count(),
                'since' => $customer->created_at?->toIso8601String(),
            ],
            'orders' => $orders->map(fn (Order $order) => [
                'id' => $order->id,
                'reference' => $order->reference,
                'status' => $order->status->value,
                'status_label' => $order->status_label,
                'status_color' => $order->status->color(),
                'total' => (float) $order->total,
                'city' => $order->city,
                'items_count' => (int) $order->items->sum('quantity'),
                'created_at' => $order->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /**
     * The headline numbers, for the top of the list.
     *
     * "Customers who ordered again" is the one worth having: it is what tells
     * the owner who their regulars are.
     */
    private function summary(): array
    {
        $repeat = Customer::query()
            ->has('orders', '>=', 2)
            ->count();

        return [
            'total' => Customer::query()->count(),
            'repeat' => $repeat,
            'new_this_month' => Customer::query()
                ->where('created_at', '>=', Carbon::now()->subDays(30))
                ->count(),
        ];
    }
}
