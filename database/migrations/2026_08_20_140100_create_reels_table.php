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
        Schema::create('reels', function (Blueprint $table) {
            $table->id();
            $table->string('title', 180);
            $table->text('description')->nullable();

            $table->string('reel_url', 500);

            /*
             | Instagram's shortcode, pulled out of the URL on save.
             |
             | Stored rather than parsed on every read: it is what the embed
             | is built from, and it identifies the reel if the URL format
             | ever changes. Unique so the same reel is not added twice.
             */
            $table->string('reel_id', 60)->nullable()->unique();

            $table->string('thumbnail_path')->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();

            $table->index(['sort_order', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reels');
    }
};
