<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);

            // Unique so it can safely become part of a public URL later.
            $table->string('slug', 170)->unique();

            // Card blurb and the long copy, kept apart so a listing never has
            // to load or truncate the full text.
            $table->string('short_description', 300)->nullable();
            $table->text('full_description')->nullable();

            // Path on the `public` disk, e.g. "services/ab12….jpg".
            $table->string('image_path')->nullable();

            // A key from config/icons.php, validated against that list.
            $table->string('icon', 40)->nullable();

            $table->decimal('price', 12, 2)->nullable();
            // True renders "From ₹4,999" rather than a flat "₹4,999".
            $table->boolean('price_from')->default(false);

            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            // The listing's default ordering.
            $table->index(['sort_order', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
