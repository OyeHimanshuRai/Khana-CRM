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
        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            $table->string('name', 180);
            $table->string('slug', 200)->unique();

            $table->string('short_description', 400)->nullable();
            $table->text('description')->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_featured')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();

            $table->string('meta_title', 200)->nullable();
            $table->string('meta_description', 320)->nullable();

            $table->timestamps();

            // The listing's default ordering.
            $table->index(['sort_order', 'name']);
        });

        /*
         | Media lives in its own table rather than as fixed columns.
         |
         | A collection's artwork is a matrix - device x position - and each
         | cell may hold an image or a video. Columns would mean twelve of
         | them, and adding a position later would be a migration. A row per
         | piece keeps the shape open.
         |
         | One piece per cell, enforced by the unique index: "the desktop
         | banner" has to mean one thing.
         */
        Schema::create('collection_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')->constrained()->cascadeOnDelete();

            // image | video - derived from the upload, not typed by hand.
            $table->string('type', 10);
            // desktop | mobile
            $table->string('device', 10);
            // banner | thumbnail | hover
            $table->string('position', 20);

            $table->string('path');
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique(['collection_id', 'device', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('collection_media');
        Schema::dropIfExists('collections');
    }
};
