<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();

            // Nullable + nullOnDelete: an audit trail must survive the
            // deletion of the account that produced it.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();

            $table->string('event', 60)->index();
            $table->string('description');

            // Optional link back to the affected record.
            $table->nullableMorphs('subject');

            $table->json('properties')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->timestamps();

            $table->index(['created_at', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
