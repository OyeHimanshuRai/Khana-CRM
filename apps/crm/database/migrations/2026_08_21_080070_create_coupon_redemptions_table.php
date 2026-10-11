<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A record of a coupon actually being spent on an order.
     *
     * Split out from the coupons migration because it references `orders`,
     * which does not exist until the migration after this one's original
     * slot - so it has to run after orders is created.
     */
    public function up(): void
    {
        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            $table->decimal('discount_amount', 12, 2);

            $table->timestamps();

            // One redemption per order - the same order cannot apply the
            // same coupon twice.
            $table->unique(['coupon_id', 'order_id']);
            $table->index(['coupon_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_redemptions');
    }
};
