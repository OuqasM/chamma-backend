<?php

namespace App\Models;

use App\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\App;

class Product extends Model
{
    use HasFactory;
    use HasTranslations;

    use SoftDeletes;

    protected string $translationForeignKey = 'product_id';

    protected $fillable = [
        'brand_id',
        'category_id',
        'slug',
        'sku',
        'price',
        'cost_price',
        'compare_at_price',
        'stock',
        'size',
        'gender',
        'is_active',
        'is_available',
        'is_featured',
        'is_new',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'compare_at_price' => 'decimal:2',
        'stock' => 'integer',
        'is_active' => 'boolean',
        'is_available' => 'boolean',
        'is_featured' => 'boolean',
        'is_new' => 'boolean',
        'rating' => 'decimal:1',
        'rating_count' => 'integer',
        'sales_count' => 'integer',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderByDesc('is_primary')->orderBy('position');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function primaryImage(): ?ProductImage
    {
        return $this->images->first();
    }

    /**
     * Whether the product is on the shelf at all. Independent of
     * `is_available`: an unavailable product is still listed, an inactive one
     * is not.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeInStock(Builder $query): Builder
    {
        return $query->where('is_available', true);
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('is_featured', true);
    }

    public function scopeNewArrivals(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where('is_new', true)->orWhere('created_at', '>=', now()->subDays(45));
        });
    }

    public function scopeDiscounted(Builder $query): Builder
    {
        return $query->whereNotNull('compare_at_price')->whereColumn('compare_at_price', '>', 'price');
    }

    /**
     * Full search surface: product names in every language, brand name and
     * category name. Matches the shopper's mental model ("Rituals", "sakura",
     * "body cream", "مسك"...).
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
        $locales = array_keys(config('chamma.locales'));

        return $query->where(function (Builder $q) use ($like, $term, $locales) {
            $q->where('products.sku', 'like', $like)
                ->orWhere('products.slug', 'like', $like)
                ->orWhereHas('translations', fn ($t) => $t
                    ->whereIn('locale', $locales)
                    ->where(fn ($n) => $n->where('name', 'like', $like)->orWhere('short_description', 'like', $like))
                )
                ->orWhereHas('brand', fn ($b) => $b
                    ->where('brands.name', 'like', $like)
                    ->orWhereHas('translations', fn ($t) => $t
                        ->whereIn('locale', $locales)
                        ->where('name', 'like', $like)
                    )
                )
                ->orWhereHas('category', fn ($c) => $c
                    ->where('categories.slug', 'like', $like)
                    ->orWhereHas('translations', fn ($t) => $t
                        ->whereIn('locale', $locales)
                        ->where('name', 'like', $like)
                    )
                );
        });
    }

    public function getFinalPriceAttribute(): float
    {
        return round((float) $this->price, 2);
    }

    public function getIsOnSaleAttribute(): bool
    {
        return $this->compare_at_price !== null && (float) $this->compare_at_price > (float) $this->price;
    }

    public function getDiscountPercentAttribute(): int
    {
        if (! $this->is_on_sale || (float) $this->compare_at_price <= 0) {
            return 0;
        }

        return (int) round((1 - ((float) $this->price / (float) $this->compare_at_price)) * 100);
    }

    /**
     * What the storefront labels "out of stock", set by the admin rather than
     * derived from the unit count, so a product can be paused for a restock
     * without being unpublished. `stock` stays the plain quantity.
     */
    public function getInStockAttribute(): bool
    {
        return (bool) $this->is_available;
    }

    public function getIsLowStockAttribute(): bool
    {
        return $this->is_available && $this->stock > 0 && $this->stock <= 3;
    }

    /**
     * Localised URL path for the SPA: /fr/products/<slug>
     */
    public function getUrlAttribute(): string
    {
        $locale = App::getLocale();

        return "/{$locale}/products/{$this->slug($locale)}";
    }
}
