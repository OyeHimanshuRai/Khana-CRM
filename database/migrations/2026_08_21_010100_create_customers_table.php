<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The customer master.
     *
     * Shop-scoped, which is the reading the SRS asks for: "each shop
     * operates with isolated customers". The same farmer buying at two
     * branches is two rows with two credit limits and two ledgers, because
     * that is how the shops actually extend credit.
     *
     * `balance` is a maintained running total - positive means the customer
     * owes the shop. It is kept in step by the ledger service; the ledger
     * rows remain the source of truth it can be rebuilt from.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();

            // Set when the customer also has a storefront login.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('code', 30)->nullable();
            $table->string('name', 150);

            // The counter looks people up by phone, so it is the near-key.
            $table->string('mobile', 20)->nullable();
            $table->string('alt_mobile', 20)->nullable();
            $table->string('email', 150)->nullable();

            $table->string('gstin', 20)->nullable();

            /* --------------------------------------------------- address */

            $table->string('address_line1', 190)->nullable();
            $table->string('address_line2', 190)->nullable();
            $table->string('village', 120)->nullable();
            $table->string('taluka', 120)->nullable();
            $table->string('district', 120)->nullable();
            $table->string('city', 90)->nullable();
            $table->string('state', 90)->nullable();
            $table->string('pincode', 12)->nullable();

            /* ------------------------------------------------ agriculture */

            $table->decimal('land_area', 10, 2)->nullable()->comment('In acres');
            $table->string('primary_crops', 190)->nullable();

            /* ------------------------------------------------------ credit */

            // retail | wholesale | farmer | dealer
            $table->string('type', 20)->default('retail')->index();

            // 0 means no credit at all: a credit sale to this customer is
            // refused rather than merely warned about.
            $table->decimal('credit_limit', 15, 2)->default(0);
            $table->unsignedSmallInteger('credit_days')->default(0);

            $table->decimal('opening_balance', 15, 2)->default(0);

            // Maintained running total. Positive = owed to the shop.
            $table->decimal('balance', 15, 2)->default(0);

            $table->boolean('allow_credit')->default(false);

            /* ------------------------------------------------------ status */

            $table->text('notes')->nullable();
            $table->string('image_path')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            // Invoices name the customer; the row has to survive a removal.

            // One phone number per shop, so the counter's lookup is
            // unambiguous. Nullable, and MySQL allows repeated NULLs - which
            // is right: walk-in customers have no number and no row to clash.
            $table->unique(['shop_id', 'mobile']);
            $table->unique(['shop_id', 'code']);

            $table->index(['shop_id', 'is_active']);
            $table->index(['shop_id', 'balance']);
            $table->index(['shop_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
