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
         | One row per sign-in, kept alongside Laravel's own `sessions` table
         | rather than instead of it.
         |
         | `sessions` is the authority on whether a session is still valid -
         | deleting a row there actually signs the device out. This table is
         | the readable history around it: which device, which browser, when
         | it was last active, and why it ended. A row survives its session,
         | so the login history stays intact after the framework prunes.
         */
        Schema::create('user_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Laravel's session id. Nullable because the row outlives it.
            $table->string('session_id')->nullable()->index();

            $table->string('ip_address', 45)->nullable();
            $table->string('device_type', 20)->nullable();
            $table->string('device_name', 80)->nullable();
            $table->string('browser', 60)->nullable();
            $table->string('browser_version', 30)->nullable();
            $table->string('operating_system', 60)->nullable();
            $table->string('os_version', 30)->nullable();
            $table->string('location', 120)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamp('login_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('logout_at')->nullable();

            // How the session ended, once it has: user | admin | expired.
            $table->string('ended_by', 20)->nullable();

            $table->timestamps();

            $table->index(['user_id', 'logout_at']);
            $table->index('last_activity_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_sessions');
    }
};
