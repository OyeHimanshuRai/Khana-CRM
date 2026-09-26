<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a branch's UPI money actually lands (SRS 8).
     *
     * ----------------------------------------------------------------------
     * On the shop, not the company
     * ----------------------------------------------------------------------
     *
     * A group's branches are frequently separate businesses to the bank even
     * when they are one company on paper - a franchise pays into its own
     * account, and a manager settling a table in Ajmer Road must not print a
     * code that credits Tonk Road. The column therefore sits beside the
     * branch's own currency and invoice prefix, which are on the shop for the
     * same reason.
     *
     * Both nullable, and null is a real, permanent state: a branch that takes
     * no UPI has none, and the bill screen simply does not offer a code. That
     * is why there is no backfill here - there is nothing true to backfill
     * with, and inventing a VPA would print a code that sends a guest's money
     * to a string somebody made up.
     *
     * `upi_name` is the payee name a phone shows before the guest confirms.
     * Kept separate from `shops.name` because the bank's registered name and
     * the name over the door are often not the same, and the one a guest is
     * asked to trust has to match their banking app rather than the signage.
     */
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->string('upi_id', 120)->nullable()->after('currency');
            $table->string('upi_name', 120)->nullable()->after('upi_id');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['upi_id', 'upi_name']);
        });
    }
};
