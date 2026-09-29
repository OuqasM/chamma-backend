<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $productId = $this->route('product')?->id;
        return [
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            // No longer admin-typed: the controller derives a unique one from the
            // name so the merchant never has to invent a reference.
            'sku' => ['nullable', 'string', 'max:60', Rule::unique('products', 'sku')->ignore($productId)],
            // Typed once, in English: see App\Concerns\HasSingleName.
            'name' => ['required', 'string', 'max:200'],
            'slug' => ['nullable', 'string', 'max:180', 'alpha_dash', Rule::unique('products', 'slug')->ignore($productId)],
            'price' => ['required', 'numeric', 'min:0', 'max:999999'],
            // What the shop pays for the unit. Internal only: never exposed by
            // the storefront resource.
            'cost_price' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'compare_at_price' => ['nullable', 'numeric', 'min:0', 'gt:price', 'max:999999'],
            'stock' => ['required', 'integer', 'min:0', 'max:100000'],
            'size' => ['nullable', 'string', 'max:60'],
            'gender' => ['nullable', 'string', Rule::in(['women', 'men', 'unisex'])],
            'is_active' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
            'is_new' => ['nullable', 'boolean'],
            // `rating` / `rating_count` are no longer admin-editable. The columns
            // stay and are still seeded and read by the storefront.

            // There is no per-language input any more: the copy is written once,
            // on the fallback translation row. Locales that were translated
            // before keep their own copy untouched.
            'description' => ['nullable', 'string'],
            'short_description' => ['nullable', 'string', 'max:300'],
            'meta_title' => ['nullable', 'string', 'max:200'],
            'meta_description' => ['nullable', 'string', 'max:300'],

            'images' => ['nullable', 'array', 'max:8'],
            'images.*' => ['string', 'max:255'],
            'remove_images' => ['nullable', 'array'],
            'remove_images.*' => ['integer'],

            // Only used when the admin has no artwork yet: a shape + tone pair
            // renders a matching bottle shot server-side.
            'artwork' => ['nullable', 'array'],
            'artwork.shape' => ['nullable', 'string', 'in:flacon,jar,mist,tube,dropper,carton'],
            'artwork.tone' => ['nullable', 'string', 'in:blush,rose,amber,espresso,ivory,plum,jade,noir'],
            'artwork.size' => ['nullable', 'string', 'max:60'],
            'artwork.label' => ['nullable', 'string', 'max:60'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('api.validation.attributes');
    }
}
