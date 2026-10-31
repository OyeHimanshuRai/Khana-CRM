<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Storefront coupons.
     *
     * v1 supports two rule shapes only - percent-off (with an optional cap)
     * and fixed-amount-off - plus a minimum order amount and usage limits.
     * No BOGO, no free-shipping, no stacking; those would need a different
     * `type` and a redemption engine this table does not attempt yet.
     *
     * Soft-deleted rather than hard-deleted: an order that already redeemed
     * a coupon must still be able to show what it was after the coupon is
     * withdrawn.
     */
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();

            $table->string('code', 40);
            $table->string('description', 190)->nullable();

            // percent | fixed
            $table->string('type', 20);
            $table->decimal('value', 12, 2);

            // Caps a percent discount in absolute rupees; ignored for fixed.
            $table->decimal('max_discount_amount', 12, 2)->nullable();
            $table->decimal('min_order_amount', 12, 2)->default(0);

            // Null means unlimited.
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('usage_limit_per_customer')->nullable()->default(1);

            $table->dateTime('starts_at')->nullable();
            $table->dateTime('expires_at')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['shop_id', 'code']);
            $table->index(['shop_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
