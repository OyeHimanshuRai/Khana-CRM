<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The product catalogue.
     *
     * Global, not shop-scoped. The SRS offers both readings; this is the
     * "global masters with shop-specific price/stock" one, because the
     * alternative makes a group-wide product report impossible and forces
     * the same fertiliser to be re-created at every branch. Per-shop price
     * and reorder levels live on product_shop; quantities on product_stocks.
     *
     * The prices here are the defaults a new shop inherits.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            /* ---------------------------------------------------- identity */

            $table->string('name', 190);
            $table->string('slug', 210)->unique();

            // Ours. Always present, because every other identifier is
            // optional and something has to be printable on a label.
            $table->string('sku', 60)->unique();

            // The manufacturer's, scanned at the counter. Unique so a scan
            // can never be ambiguous, nullable because plenty of loose stock
            // has none until we print one.
            $table->string('barcode', 60)->nullable()->unique();

            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tax_rate_id')->nullable()->constrained()->nullOnDelete();

            $table->string('hsn_code', 20)->nullable()->index();
            $table->string('manufacturer', 150)->nullable();

            /* ------------------------------------------------- commercial */

            // 15,4 throughout: money at 2 would lose per-unit costs on
            // anything sold in grams or millilitres.
            $table->decimal('purchase_price', 15, 4)->default(0);
            $table->decimal('mrp', 15, 4)->default(0);
            $table->decimal('selling_price', 15, 4)->default(0);
            $table->decimal('discount_percent', 6, 3)->default(0);

            // Whether selling_price already contains the tax. Decides which
            // way the POS has to work the GST out.
            $table->boolean('tax_inclusive')->default(true);

            /* -------------------------------------------------- inventory */

            $table->decimal('min_stock', 15, 3)->default(0);
            $table->decimal('reorder_level', 15, 3)->default(0);

            // Whether this product's stock is tracked batch by batch. Off
            // for hardware, on for anything with an expiry date.
            $table->boolean('track_batches')->default(false);

            /* ------------------------------------------------ agriculture */

            $table->string('crop', 190)->nullable()
                ->comment('Crops this input is meant for, comma separated');
            $table->string('pest_disease', 190)->nullable();
            $table->string('technical_name', 190)->nullable()
                ->comment('Active ingredient, e.g. Imidacloprid 17.8% SL');
            $table->text('dosage')->nullable();
            $table->text('usage_instructions')->nullable();
            $table->text('safety_information')->nullable();

            /* ------------------------------------------------- descriptive */

            $table->text('short_description')->nullable();
            $table->longText('description')->nullable();

            // Primary image; the rest live in product_images.
            $table->string('image_path')->nullable();

            /* ------------------------------------------------------ status */

            $table->boolean('is_active')->default(true)->index();

            // Whether it appears in the online store at all.
            $table->boolean('is_published')->default(false)->index();
            $table->boolean('is_featured')->default(false);

            /* --------------------------------------------------------- SEO */

            $table->string('meta_title', 190)->nullable();
            $table->string('meta_description', 300)->nullable();
            $table->string('meta_keywords', 300)->nullable();

            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            // Invoice lines and stock rows point at products; they must stay
            // readable after a product is withdrawn, hence the soft delete.

            $table->index(['is_active', 'is_published']);
            $table->index(['category_id', 'is_active']);
            $table->index(['brand_id', 'is_active']);
            $table->index(['sort_order', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
