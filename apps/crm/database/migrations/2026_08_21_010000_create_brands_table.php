<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Product brands / manufacturers.
     *
     * A catalogue master, so deliberately NOT shop-scoped: "Bayer" is the
     * same company at every branch, and duplicating it per shop would make
     * a group-wide brand report impossible to write. What varies per shop is
     * price and stock, and those live on product_shop and product_stocks.
     */
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->id();

            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->text('description')->nullable();

            // The company behind the brand, when it differs from the label.
            $table->string('manufacturer', 150)->nullable();
            $table->string('website', 190)->nullable();

            $table->string('logo_path')->nullable();

            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['sort_order', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brands');
    }
};
