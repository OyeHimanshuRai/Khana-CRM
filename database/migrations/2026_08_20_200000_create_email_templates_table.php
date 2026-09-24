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
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('name', 200);

            /*
             | A stable handle for reaching a template from code, so a future
             | transactional send can ask for "order-confirmation" rather than
             | an id that means nothing and changes between environments.
             */
            $table->string('slug', 220)->unique();

            $table->string('category', 80)->nullable()->index();
            $table->string('description', 400)->nullable();

            $table->string('subject', 250);
            $table->string('preheader', 250)->nullable();
            $table->longText('content')->nullable();

            /*
             | Which merge tags this template actually uses, detected from the
             | content on every save. Derived rather than typed in: a declared
             | list drifts out of step with the body the first time somebody
             | edits one and not the other.
             */
            $table->json('variables')->nullable();

            $table->boolean('is_active')->default(true)->index();

            // How often it has been used to start a campaign.
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamp('last_used_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // Captured as it was, so the row stays attributable after the
            // account is deleted - which nulls created_by.
            $table->string('created_by_name', 120)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
