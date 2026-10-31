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
         | An IP block, enforced at middleware level on the login route.
         |
         | user_id scopes the block: set, it stops that one account signing in
         | from the address; null, it stops the address entirely. Both shapes
         | are useful - "this account is compromised from that cafe" is not
         | the same as "that address is hostile".
         |
         | expires_at null means permanent.
         */
        Schema::create('blocked_ips', function (Blueprint $table) {
            $table->id();
            $table->string('ip_address', 45)->index();

            // Null = the address is blocked for every account.
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('reason', 255)->nullable();
            $table->foreignId('blocked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('blocked_by_name')->nullable();

            $table->timestamp('blocked_at')->nullable();
            // Null = permanent.
            $table->timestamp('expires_at')->nullable()->index();

            // active | lifted
            $table->string('status', 20)->default('active')->index();
            $table->timestamp('lifted_at')->nullable();

            $table->timestamps();

            $table->index(['ip_address', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blocked_ips');
    }
};
