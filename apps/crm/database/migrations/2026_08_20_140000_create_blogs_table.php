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
        Schema::create('blogs', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->string('slug', 220)->unique();

            $table->string('short_description', 400)->nullable();
            $table->longText('content')->nullable();

            $table->string('featured_image_path')->nullable();

            /*
             | The author is a real account, so a post survives being
             | reassigned and the name is never a typo. Nullable + nullOnDelete
             | so deleting a user does not take their posts with them; the
             | name is denormalised alongside for exactly that case.
             */
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_name', 120)->nullable();

            /*
             | A plain string, like faqs.category. The `categories` table is
             | for products; pointing blog posts at it would tie two
             | unrelated taxonomies together. The form offers the labels
             | already in use, so it stays tidy without a join.
             */
            $table->string('category', 80)->nullable()->index();

            // Stored as a JSON array; entered comma separated.
            $table->json('tags')->nullable();

            $table->timestamp('published_at')->nullable()->index();

            $table->string('seo_title', 200)->nullable();
            $table->string('seo_description', 320)->nullable();

            // draft | published | inactive
            $table->string('status', 20)->default('draft')->index();
            $table->boolean('is_featured')->default(false)->index();

            $table->timestamps();

            $table->index(['status', 'published_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blogs');
    }
};
