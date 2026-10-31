<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The storefront cart.
     *
     * One row per customer per shop, reused indefinitely - there is no
     * `status` column because a cart is never "closed", only emptied.
     * `CartService::clear()` deletes the child rows once an Order is placed
     * from them.
     *
     * Deliberately no price on a cart line. Unlike an Order or Invoice line,
     * a cart is not a document - it has made no promise to anyone yet - so
     * every line is re-priced live from Product::counterPriceFor() at
     * render and at checkout. Freezing a price here would let a shelf price
     * change silently go stale in someone's cart for days.
     */
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['shop_id', 'customer_id']);
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->decimal('quantity', 15, 3);

            $table->timestamps();

            $table->unique(['cart_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};
