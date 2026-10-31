<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Switching on the OTP step (SRS 3.8).
     *
     * ------------------------------------------------------------------
     * Off by default, and per branch
     * ------------------------------------------------------------------
     *
     * The doc calls it optional and it has to stay that way. An OTP between
     * a hungry guest and their order is friction a restaurant chooses
     * deliberately - usually because they have been burnt by prank orders -
     * and it is not something an upgrade should impose on anybody.
     *
     * Per branch rather than per company: a busy high-street outlet and a
     * quiet one inside an office park have genuinely different answers.
     *
     * ------------------------------------------------------------------
     * Verified once per sitting
     * ------------------------------------------------------------------
     *
     * `table_sessions.mobile_verified_at` is the whole enforcement. A table
     * verifies when it places its first order and is not asked again for the
     * second round - which is the same rule the guest's name already
     * follows, and for the same reason: a diner who has proved who they are
     * has proved it for the meal, not for the course.
     */
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->boolean('requires_otp')->default(false)->after('modules');
        });

        Schema::table('table_sessions', function (Blueprint $table) {
            $table->timestamp('mobile_verified_at')->nullable()->after('guest_mobile');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('requires_otp');
        });

        Schema::table('table_sessions', function (Blueprint $table) {
            $table->dropColumn('mobile_verified_at');
        });
    }
};
