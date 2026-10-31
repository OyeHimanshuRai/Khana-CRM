<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Editable standalone pages: privacy policy, terms, refund policy, about.
     *
     * A payment gateway checks that a merchant's site publishes these before
     * it will take live money, so they cannot be a file somebody has to
     * deploy to change.
     */
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('title', 160);
            $table->string('slug', 120)->unique();
            $table->longText('body');
            $table->string('meta_description', 300)->nullable();

            $table->boolean('is_active')->default(true)->index();
            $table->boolean('show_in_footer')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['is_active', 'show_in_footer', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
