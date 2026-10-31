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
        /*
         | Every login attempt, successful or not.
         |
         | user_id is nullable and the email is denormalised, because a failed
         | attempt may name an address that has no account - which is exactly
         | the attempt worth keeping. The row also survives the user being
         | deleted, so the audit trail does not disappear with the account.
         |
         | The IP history on the user detail screen is derived from this table
         | rather than a separate one: an address is only interesting here
         | because something tried to sign in from it.
         */
        Schema::create('login_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email')->nullable()->index();

            // success | failed | blocked
            $table->string('status', 20)->index();
            // Why it failed: bad_password, unknown_email, inactive, no_admin_access, ip_blocked, throttled
            $table->string('reason', 40)->nullable();

            $table->string('ip_address', 45)->nullable()->index();
            $table->string('device_type', 20)->nullable();
            $table->string('device_name', 80)->nullable();
            $table->string('browser', 60)->nullable();
            $table->string('browser_version', 30)->nullable();
            $table->string('operating_system', 60)->nullable();
            $table->string('os_version', 30)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('login_histories');
    }
};
