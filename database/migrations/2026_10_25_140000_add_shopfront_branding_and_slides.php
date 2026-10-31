<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->string('storefront_logo_path')->nullable();
            $table->string('storefront_footer_logo_path')->nullable();
            $table->string('storefront_tagline', 120)->nullable();
        });

        Schema::create('storefront_slides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->string('button_label', 50)->nullable();
            $table->string('button_url', 500)->nullable();
            $table->string('desktop_image_path');
            $table->string('mobile_image_path')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->index(['shop_id', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_slides');

        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn([
                'storefront_logo_path',
                'storefront_footer_logo_path',
                'storefront_tagline',
            ]);
        });
    }
};
