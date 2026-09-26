<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The tenant master.
     *
     * Every operational row in the system hangs off one of these. The table
     * carries the branding, tax identity and invoice numbering that a shop
     * prints on its own paperwork, because those differ per branch even when
     * the business is one legal entity.
     */
    public function up(): void
    {
        Schema::create('shops', function (Blueprint $table) {
            $table->id();

            $table->string('name', 150);

            // Short human key used on invoice numbers and in the switcher,
            // e.g. "MAIN" -> INV/MAIN/2026/0001.
            $table->string('code', 20)->unique();

            $table->string('slug', 160)->unique();

            // Registered name, when it differs from the trading name.
            $table->string('legal_name', 180)->nullable();

            /* ------------------------------------------------ tax identity */

            $table->string('gstin', 20)->nullable();
            $table->string('pan', 15)->nullable();
            $table->string('licence_no', 60)->nullable()
                ->comment('Fertiliser/pesticide/seed dealer licence');

            /* ------------------------------------------------------ contact */

            $table->string('phone', 30)->nullable();
            $table->string('email', 150)->nullable();

            $table->string('address_line1', 190)->nullable();
            $table->string('address_line2', 190)->nullable();
            $table->string('city', 90)->nullable();
            $table->string('state', 90)->nullable();

            // GST place-of-supply code; drives intra vs inter-state tax split.
            $table->string('state_code', 4)->nullable();

            $table->string('pincode', 12)->nullable();
            $table->string('country', 90)->default('India');

            /* ------------------------------------------------------ branding */

            $table->string('logo_path')->nullable();

            /* ------------------------------------------- invoice numbering */

            // Series is per shop so two branches billing at the same moment
            // can never mint the same invoice number.
            $table->string('invoice_prefix', 20)->default('INV');
            $table->unsignedBigInteger('invoice_next')->default(1);

            $table->string('pos_prefix', 20)->default('POS');
            $table->unsignedBigInteger('pos_next')->default(1);

            /* -------------------------------------------------- operational */

            $table->string('currency', 8)->default('INR');
            $table->string('timezone', 64)->default('Asia/Kolkata');

            // Business rules the SRS lets the owner turn on per shop.
            $table->boolean('allow_negative_stock')->default(false);
            $table->boolean('block_expired_sale')->default(true);

            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            // The listing's default ordering.
            $table->index(['sort_order', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shops');
    }
};
