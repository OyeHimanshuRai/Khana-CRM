<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Password-reset tokens for storefront customers.
     *
     * Kept apart from the admin `password_reset_tokens` table on purpose: an
     * admin User and a Customer can legitimately share an email address, and
     * that table is keyed only by email - sharing it would let a reset token
     * issued for one account be consumed against the other.
     */
    public function up(): void
    {
        Schema::create('customer_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_password_reset_tokens');
    }
};
