<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Sign-in history and blocked addresses. Every sign-in to every product
| passes through Accounts, so this is the one place both are kept.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // What was typed: an email or a mobile number.
            $table->string('identifier')->nullable();
            // success | failed | blocked | locked
            $table->string('status', 12);
            $table->string('reason', 40)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('blocked_ips', function (Blueprint $table) {
            $table->id();
            $table->string('ip_address', 45)->index();
            // Null blocks the address for everybody; set, only for that person.
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('reason')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocked_ips');
        Schema::dropIfExists('login_history');
    }
};
