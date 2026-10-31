<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Turn a customer row into something that can also sign in to the
     * storefront.
     *
     * `password` is nullable because every customer created by staff at the
     * counter has never had one - a storefront account is something a
     * customer opts into later, not a side effect of being billed.
     * `email_verified_at` exists for future enforcement only; nothing reads
     * it yet, since a mobile-only customer has no address to verify and
     * there is no OTP channel to verify one with.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('password')->nullable()->after('email');
            $table->rememberToken()->after('password');
            $table->timestamp('email_verified_at')->nullable()->after('email');

            // Nullable-safe: MySQL allows repeated NULLs, so shops whose
            // customers have no email are unaffected.
            $table->unique(['shop_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['shop_id', 'email']);
            $table->dropColumn(['password', 'remember_token', 'email_verified_at']);
        });
    }
};
