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
        Schema::table('users', function (Blueprint $table) {
            /*
             | Two separate gates, deliberately:
             |   is_admin   may this account reach /admin at all
             |   is_active  may this account sign in at all
             | Deactivating is the reversible "suspend" that stops short of a
             | delete, and it is checked before credentials are even weighed.
             */
            $table->boolean('is_active')->default(true)->after('is_admin');

            $table->string('mobile', 30)->nullable()->after('email');

            $table->timestamp('last_login_at')->nullable()->after('avatar_path');
            // Touched by TrackUserSession; drives the online/offline dot.
            $table->timestamp('last_seen_at')->nullable()->after('last_login_at');
            $table->unsignedInteger('login_count')->default(0)->after('last_seen_at');

            $table->index('last_seen_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['last_seen_at']);
            $table->dropColumn([
                'is_active', 'mobile', 'last_login_at', 'last_seen_at', 'login_count',
            ]);
        });
    }
};
