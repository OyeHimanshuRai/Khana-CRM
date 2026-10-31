<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The supplier master.
     *
     * Shop-scoped for the same reason customers are: each branch settles its
     * own purchase ledger, on its own terms, with the same distributor.
     *
     * `balance` here is the mirror of the customer one - positive means the
     * shop owes the supplier.
     */
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();

            $table->string('code', 30)->nullable();
            $table->string('name', 150);
            $table->string('company', 180)->nullable();

            $table->string('contact_person', 120)->nullable();
            $table->string('mobile', 20)->nullable();
            $table->string('alt_mobile', 20)->nullable();
            $table->string('email', 150)->nullable();

            $table->string('gstin', 20)->nullable();
            $table->string('pan', 15)->nullable();

            $table->string('address_line1', 190)->nullable();
            $table->string('address_line2', 190)->nullable();
            $table->string('city', 90)->nullable();
            $table->string('state', 90)->nullable();
            $table->string('state_code', 4)->nullable();
            $table->string('pincode', 12)->nullable();

            /* -------------------------------------------------- settlement */

            $table->unsignedSmallInteger('credit_days')->default(0);
            $table->decimal('credit_limit', 15, 2)->default(0);
            $table->decimal('opening_balance', 15, 2)->default(0);

            // Maintained running total. Positive = the shop owes them.
            $table->decimal('balance', 15, 2)->default(0);

            $table->string('bank_name', 120)->nullable();
            $table->string('bank_account', 40)->nullable();
            $table->string('bank_ifsc', 20)->nullable();

            $table->text('notes')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['shop_id', 'code']);
            $table->index(['shop_id', 'is_active']);
            $table->index(['shop_id', 'name']);
            $table->index(['shop_id', 'balance']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
