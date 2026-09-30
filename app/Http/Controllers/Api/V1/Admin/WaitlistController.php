<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\WaitlistEntry;
use App\Services\WaitlistEntryService;
use App\Services\WaitlistService;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The waiting list, for the owner.
 *
 * Two jobs: show who is outstanding, and record that they were reached. There
 * is no delete endpoint. A number on this list is a person who gave it, and
 * removing it from a table next to a delete button would quietly suggest the
 * owner can erase the fact that they were ever waiting for a product — so the
 * record stays and is marked, and only disappears with the product itself.
 */
class WaitlistController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Null means "all products"; the panel sends it when the owner has
            // not chosen one, which is the same as an empty filter rather than
            // a request for the literal product with no id.
            'product' => ['nullable', 'integer', 'exists:products,id'],
            // `pending` is the working view: who to call. `notified` is for
            // checking whether somebody was already reached.
            'status' => ['nullable', 'string', 'in:pending,notified,all'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $status = $validated['status'] ?? 'pending';
        $productId = $validated['product'] ?? null;
        $perPage = (int) ($validated['per_page'] ?? 50);

        $query = WaitlistEntry::query()
            ->with(['product.translations'])
            ->when($productId, fn ($q) => $q->where('product_id', $productId))
            ->when($status === 'pending', fn ($q) => $q->pending())
            ->when($status === 'notified', fn ($q) => $q->notified())
            ->recent();

        $page = $query->paginate($perPage)->withQueryString();

        return response()->json([
            'waitlist' => collect($page->items())->map(fn (WaitlistEntry $entry) => [
                'id' => $entry->id,
                'phone' => $entry->phone,
                // A wa.me link the owner can open straight from the table. The
                // whole purpose of collecting a number is that it gets used.
                'whatsapp_url' => $entry->whatsappUrl(),
                'locale' => $entry->locale,
                'waiting_since' => $entry->created_at?->toIso8601String(),
                'notified_at' => $entry->notified_at?->toIso8601String(),
                'notified_channel' => $entry->notified_channel,
                'product' => [
                    'id' => $entry->product_id,
                    'name' => $entry->product?->name(),
                    'slug' => $entry->product?->slug(),
                    // Whether the product is actually buyable now. The owner
                    // does not want a calling list for something that went back
                    // out of stock while they were away.
                    'in_stock' => (bool) $entry->product?->in_stock,
                    'stock' => (int) ($entry->product?->stock ?? 0),
                ],
            ])->values(),
            'meta' => $page->toArray() + [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'filters' => [
                'product' => $productId,
                'status' => $status,
            ],
            'summary' => $this->summary(),
            'products' => $this->productsForFilter(),
        ]);
    }

    /**
     * Record that these people were reached.
     *
     * Bulk on purpose: an owner who worked down a page of ten numbers should
     * clear all ten in one action, not ten round trips. Ids are optional so
     * "I called the whole filtered list" is one request.
     */
    public function markNotified(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['nullable', 'array', 'max:200'],
            'ids.*' => ['integer', 'exists:waitlist_entries,id'],
            'product' => ['nullable', 'integer', 'exists:products,id'],
            'status' => ['nullable', 'string', 'in:pending,all'],
            'channel' => ['nullable', 'string', 'in:whatsapp,call,other'],
        ]);

        $ids = $validated['ids'] ?? [];
        $status = $validated['status'] ?? 'pending';
        $productId = $validated['product'] ?? null;

        // An empty selection with no product filter would mean "mark every
        // outstanding entry in the shop". That is not a mistake worth letting
        // a mis-click perform, so the request has to name what it is marking.
        if ($ids === [] && ! $productId) {
            throw ValidationException::withMessages([
                'ids' => __('api.validation.waitlist_selection_required'),
            ]);
        }

        $entries = WaitlistEntry::query()
            ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))
            ->when($ids === [], fn ($q) => $q->where('product_id', $productId))
            ->when($status === 'pending', fn ($q) => $q->pending())
            ->get();

        $marked = WaitlistEntryService::markNotified($entries, $validated['channel'] ?? 'whatsapp');

        return response()->json([
            'marked' => $marked,
        ]);
    }

    /**
     * Add a number the owner collected themselves.
     *
     * Waiting lists are started in the shop too — someone rings, asks to be
     * called, and the number is written down. Going through the same service
     * means a number entered by hand and the same number typed on the product
     * page merge into one row instead of producing two calls to make.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'phone' => ['required', 'string'],
            'locale' => ['nullable', 'string', 'max:5'],
        ]);

        // Validated here rather than through StoreWaitlistRequest: the owner
        // is typing into a panel, not a public form, and the error needs to say
        // which of their entries was wrong.
        if (PhoneNumber::normalise((string) $validated['phone']) === null) {
            throw ValidationException::withMessages([
                'phone' => __('api.validation.phone'),
            ]);
        }

        $product = Product::query()->findOrFail($validated['product_id']);

        $service = app(WaitlistService::class);
        $result = $service->addFromAdmin($product, $validated['phone'], $validated['locale'] ?? null);

        return response()->json([
            'entry' => [
                'id' => $result['entry']->id,
                'phone' => $result['entry']->phone,
                'created' => $result['created'],
            ],
        ], $result['created'] ? 201 : 200);
    }

    /**
     * Headline counts, for the top of the panel.
     *
     * Outstanding is the number that matters and is therefore the only one
     * computed against the whole table. The rest are shaped by the filtered
     * view so the summary matches what the table below it shows.
     */
    private function summary(): array
    {
        return [
            'pending_total' => WaitlistEntry::query()->pending()->count(),
            'pending_products' => (int) WaitlistEntry::query()
                ->pending()
                ->distinct()
                ->count('product_id'),
            'notified_total' => WaitlistEntry::query()->notified()->count(),
            // The products most people are waiting on. Knowing which perfume
            // keeps selling out is worth more than the raw list of numbers,
            // because it is what tells the owner what to reorder.
            'top_products' => WaitlistEntry::query()
                ->pending()
                ->selectRaw('product_id, count(*) as total')
                ->groupBy('product_id')
                ->orderByDesc('total')
                ->limit(5)
                ->pluck('total', 'product_id')
                ->all(),
        ];
    }

    /**
     * The product filter's options.
     *
     * Every product, not only those with entries: the owner needs to be able to
     * add a number to a product that nobody has signed up for yet.
     */
    private function productsForFilter(): array
    {
        return Product::query()
            ->active()
            ->with('translations')
            ->orderBy('id')
            ->get()
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name(),
                'slug' => $product->slug(),
            ])
            ->all();
    }
}
