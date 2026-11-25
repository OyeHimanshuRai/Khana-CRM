<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a customer chose to start: a free trial, or paying for the plan up front.
 *
 * Chosen on the registration form and shown to the customer (dashboard) and to the
 * platform (Companies list, license panel). Null for everyone who registered before
 * the choice existed - the screens say "not recorded" rather than guess.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // trial | paid
            $table->string('signup_type', 10)->nullable()->after('license_exempt')->index();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('signup_type');
        });
    }
};
