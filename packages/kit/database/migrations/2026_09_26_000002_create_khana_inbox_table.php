<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| The receiving half. Every event another service sends is stored here once -
| the unique event_id is what makes a repeat harmless - and applied later, in
| order, one stream at a time. A stream is one sender's events for one outlet,
| so an event that fails pauses that outlet only.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('khana_inbox', function (Blueprint $table) {
            $table->id();
            $table->char('event_id', 26)->unique();
            $table->string('source', 40);
            $table->unsignedBigInteger('seq');
            $table->string('type', 100);
            $table->unsignedSmallInteger('version');
            $table->char('tenant_id', 26)->nullable();
            $table->char('outlet_id', 26)->nullable();
            $table->string('stream', 80);
            $table->dateTime('occurred_at');
            $table->json('data');
            $table->string('status', 12)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->string('skip_reason')->nullable();
            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('applied_at')->nullable();

            $table->index(['status', 'id']);
            $table->index(['stream', 'status']);
            $table->index(['source', 'seq']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('khana_inbox');
    }
};
