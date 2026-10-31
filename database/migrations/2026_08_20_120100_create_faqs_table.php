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
        Schema::create('faqs', function (Blueprint $table) {
            $table->id();
            $table->string('question', 300);
            $table->text('answer');

            /*
             | A plain string rather than a foreign key.
             |
             | The `categories` table is for products; pointing FAQs at it
             | would tie two unrelated things together, and a second table
             | for what is usually a handful of labels ("Billing", "Shipping")
             | is more machinery than the job needs. The form offers the
             | values already in use, so it stays tidy without a join.
             */
            $table->string('category', 80)->nullable()->index();

            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            // Grouped by category, ordered within it - how the page reads.
            $table->index(['category', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('faqs');
    }
};
