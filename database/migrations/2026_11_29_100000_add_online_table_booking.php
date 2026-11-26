<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Online table booking: each outlet's booking rules, and a hold + deposit on the reservation it creates.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->json('online_booking')->nullable()->after('storefront_tagline');
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->timestamp('hold_expires_at')->nullable()->after('closed_at');
            $table->decimal('deposit_amount', 10, 2)->default(0)->after('hold_expires_at');
            $table->index(['status', 'hold_expires_at']);
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropIndex(['status', 'hold_expires_at']);
            $table->dropColumn(['hold_expires_at', 'deposit_amount']);
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('online_booking');
        });
    }
};
