<?php

namespace App\Http\Resources;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Status wording follows the locale the caller is browsing in, so the
        // admin list can be read in French while the shopper's own language
        // remains available on `locale`.
        $labelLocale = app()->getLocale();
        $orderLocale = $this->locale;

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'locale' => $orderLocale,

            'customer' => [
                'first_name' => $this->first_name,
                'last_name' => $this->last_name,
                'name' => $this->customer_name,
                'phone' => $this->phone,
                'email' => $this->email,
            ],

            'shipping' => [
                'city' => $this->city,
                'address' => $this->address,
                'notes' => $this->notes,
            ],

            'payment_method' => $this->payment_method,
            'payment_method_label' => app(PaymentService::class)->label($this->payment_method, $labelLocale),
            'payment_status' => $this->payment_status,
            // Only a bank transfer needs the shopper to act: the details and
            // the WhatsApp link to send the receipt on.
            'payment_instructions' => $this->payment_method === 'bank_transfer'
                ? app(PaymentService::class)->instructions($this->resource, $orderLocale)
                : null,

            'status' => $this->status->value,
            'status_label' => $this->status->label($labelLocale),
            'status_color' => $this->status->color(),
            'next_status' => $this->status->next()?->value,

            'subtotal' => (float) $this->subtotal,
            'shipping_cost' => (float) $this->shipping_cost,
            'total' => (float) $this->total,
            'currency' => config('chamma.currency.code'),

            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'name' => $item->product_name,
                'slug' => $item->product_slug,
                'sku' => $item->product_sku,
                'image' => $item->product_image
                    ? Storage::disk(config('chamma.disk'))->url($item->product_image)
                    : null,
                'unit_price' => (float) $item->unit_price,
                'quantity' => (int) $item->quantity,
                'subtotal' => (float) $item->subtotal,
            ])->values()),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
