<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The 14-digit FSSAI licence a restaurant must print on every bill.
     *
     * Its own column rather than `licence_no`: that one is the dealer
     * licence (fertiliser, seed) and a shop can hold both.
     */
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->string('fssai_no', 20)->nullable()->after('licence_no');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('fssai_no');
        });
    }
};
