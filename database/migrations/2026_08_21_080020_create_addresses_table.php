<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A customer's storefront address book.
     *
     * `shop_id` is denormalised alongside `customer_id` even though a
     * customer already belongs to exactly one shop - every storefront query
     * here is scoped explicitly (see Order/Coupon docblocks for why
     * ShopScope cannot be relied on under the `customer` guard), and having
     * shop_id directly on the row is what makes that scoping a plain
     * `where()` instead of a join back to customers.
     *
     * Rows here are never referenced after checkout - Order snapshots the
     * chosen address onto itself - so this table has no soft deletes and no
     * history to preserve.
     */
    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            $table->string('label', 40)->nullable();
            $table->string('recipient_name', 150);
            $table->string('mobile', 20);

            $table->string('address_line1', 190);
            $table->string('address_line2', 190)->nullable();
            $table->string('village', 120)->nullable();
            $table->string('taluka', 120)->nullable();
            $table->string('district', 120)->nullable();
            $table->string('city', 90)->nullable();
            $table->string('state', 90)->nullable();
            $table->string('pincode', 12)->nullable();

            $table->boolean('is_default')->default(false);

            $table->timestamps();

            $table->index(['customer_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
