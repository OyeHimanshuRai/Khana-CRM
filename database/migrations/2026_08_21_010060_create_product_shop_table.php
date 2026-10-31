<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-shop overrides on a global product.
     *
     * Every column here is nullable and means "use the product's own value".
     * A shop that never touches pricing therefore has no rows at all, and
     * changing a price centrally still reaches it - which is the whole point
     * of keeping the catalogue global.
     */
    public function up(): void
    {
        Schema::create('product_shop', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->decimal('purchase_price', 15, 4)->nullable();
            $table->decimal('mrp', 15, 4)->nullable();
            $table->decimal('selling_price', 15, 4)->nullable();
            $table->decimal('discount_percent', 6, 3)->nullable();

            $table->decimal('min_stock', 15, 3)->nullable();
            $table->decimal('reorder_level', 15, 3)->nullable();

            // Withdraw a product from one branch without touching the others.
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['shop_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_shop');
    }
};
