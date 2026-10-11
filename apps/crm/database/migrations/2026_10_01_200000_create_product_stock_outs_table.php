<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What ERP's stock says cannot be served, one outlet at a time.
     *
     * A row means "this outlet's stock cannot cover a portion of this dish"
     * - or of one size of it. ERP says so with erp.availability.changed,
     * which is always about one outlet, and until this table existed the
     * answer was written onto products.is_sold_out and
     * product_variants.is_available. Those belong to the whole business, so
     * one outlet running out of paneer took the dish off every outlet's
     * menu. They go back to being what they always were: the switches staff
     * throw by hand.
     *
     * product_variant_id is 0 for the whole dish rather than NULL, so one
     * plain unique index covers both kinds of row - a NULL would let the
     * same dish be marked out twice. That is also why it carries no foreign
     * key. A size taken off the form leaves its row behind; nothing reads a
     * row whose size is gone, and the nightly check clears it.
     *
     * No row is the normal case and means "ERP has said nothing": on sale.
     * See App\Support\StockOuts, the only thing that reads or writes here.
     */
    public function up(): void
    {
        Schema::create('product_stock_outs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('product_variant_id')->default(0);

            $table->timestamps();

            $table->unique(['shop_id', 'product_id', 'product_variant_id'], 'product_stock_outs_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_stock_outs');
    }
};
