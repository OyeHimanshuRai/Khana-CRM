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
        Schema::create('sliders', function (Blueprint $table) {
            $table->id();
            $table->string('title', 180);
            $table->text('description')->nullable();

            // Position within its layout; the listing's default ordering.
            $table->unsignedInteger('item_no')->default(1);

            // A key from config/slider_layouts.php, validated against it.
            $table->string('layout', 40)->index();

            $table->string('redirect_url', 500)->nullable();

            /*
             | Four independent media slots, all paths on the `public` disk.
             |
             | Separate columns rather than a media table: the set is fixed
             | and each slot means something specific to the front end, so
             | "the desktop video" is a column, not a row that has to be
             | found by type.
             */
            $table->string('desktop_image_path')->nullable();
            $table->string('desktop_video_path')->nullable();
            $table->string('mobile_image_path')->nullable();
            $table->string('mobile_video_path')->nullable();

            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();

            $table->index(['layout', 'item_no']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sliders');
    }
};
