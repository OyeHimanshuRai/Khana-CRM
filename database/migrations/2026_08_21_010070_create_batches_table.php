<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A lot of one product, as received.
     *
     * Shop-scoped: the same batch number from the same supplier can arrive
     * at two branches on different days at different costs, and each has to
     * value and expire its own holding independently.
     *
     * The cost is captured here rather than only on the purchase line so
     * that stock valuation and margin have a per-lot basis to work from.
     */
    public function up(): void
    {
        Schema::create('batches', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->string('batch_no', 80);

            $table->date('mfg_date')->nullable();
            $table->date('expiry_date')->nullable();

            // What this lot cost and what it should sell for. Both may
            // differ from the product master - that is the reason batches
            // exist at all.
            $table->decimal('purchase_price', 15, 4)->default(0);
            $table->decimal('mrp', 15, 4)->default(0);
            $table->decimal('selling_price', 15, 4)->default(0);

            $table->string('supplier_batch_ref', 80)->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // One row per lot per product per shop.
            $table->unique(['shop_id', 'product_id', 'batch_no']);

            // The near-expiry sweep runs on this.
            $table->index(['shop_id', 'expiry_date']);
            $table->index(['product_id', 'expiry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batches');
    }
};
