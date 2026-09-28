<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            // Canonical, latin, ASCII: used for stable URLs and ordering.
            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->string('logo')->nullable();
            $table->string('origin', 80)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'position']);
        });

        Schema::create('brand_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('name', 120);
            $table->string('tagline')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['brand_id', 'locale']);
            $table->index(['locale', 'brand_id']);
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 140)->unique();
            $table->string('image')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'position']);
        });

        Schema::create('category_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 300)->nullable();
            $table->timestamps();

            $table->unique(['category_id', 'locale']);
            $table->index(['locale', 'category_id']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('slug', 180)->unique();
            $table->string('sku', 60)->unique();
            $table->decimal('price', 10, 2);
            $table->decimal('compare_at_price', 10, 2)->nullable();
            $table->unsignedInteger('stock')->default(0);
            $table->string('size', 60)->nullable();
            $table->string('gender', 20)->default('unisex');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_new')->default(false);
            $table->unsignedInteger('sales_count')->default(0);
            $table->decimal('rating', 2, 1)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'brand_id']);
            $table->index(['is_active', 'category_id']);
            $table->index(['is_active', 'price']);
            $table->index(['is_active', 'created_at']);
            $table->index(['is_active', 'is_featured']);
            $table->index(['is_active', 'is_new']);
        });

        Schema::create('product_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 5);
            // Localised slug: /ar/products/<slug> may differ from /en/products/<slug>.
            $table->string('slug', 200);
            $table->string('name', 200);
            $table->string('short_description', 300)->nullable();
            $table->text('description');
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 300)->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'locale']);
            $table->unique(['locale', 'slug']);
            $table->index(['locale', 'name']);
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('alt')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['product_id', 'position']);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 24)->unique();
            $table->string('locale', 5)->default('fr');
            $table->string('first_name', 80);
            $table->string('last_name', 80);
            $table->string('customer_name', 160);
            $table->string('phone', 32);
            $table->string('email')->nullable();
            $table->string('city', 80);
            $table->string('address');
            $table->text('notes')->nullable();
            $table->string('payment_method', 20)->default('cod');
            $table->string('payment_status', 20)->default('pending');
            $table->decimal('subtotal', 10, 2);
            $table->decimal('shipping_cost', 10, 2)->default(0);
            $table->decimal('total', 10, 2);
            $table->string('status', 20)->default('pending');
            $table->timestamps();

            $table->index('status');
            $table->index('created_at');
            $table->index('phone');
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            // Kept for reporting, but nullable: historical orders survive product deletion.
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            // Snapshots — the order stays correct even if the catalogue changes later.
            $table->string('product_name');
            $table->string('product_slug')->nullable();
            $table->string('product_sku')->nullable();
            $table->string('product_image')->nullable();
            $table->string('locale', 5)->default('fr');
            $table->decimal('unit_price', 10, 2);
            $table->unsignedInteger('quantity');
            $table->decimal('subtotal', 10, 2);

            $table->index(['order_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('product_translations');
        Schema::dropIfExists('products');
        Schema::dropIfExists('category_translations');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('brand_translations');
        Schema::dropIfExists('brands');
    }
};
