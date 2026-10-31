<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which lines of business a branch actually runs.
     *
     * A counter that only takes orders has no use for purchase orders; a
     * central kitchen has no till. The shop picks from config/modules.php when
     * it is set up, and what it picks decides which modules its dashboard,
     * its sidebar and its routes offer.
     *
     * A column on `shops` rather than a pivot table, deliberately. There is
     * exactly one row per shop, it is never joined and never queried across -
     * a `shop_modules` table would be a join and an index to answer a
     * question the row already holds.
     *
     * **Nullable, and null is not the same as empty.** Null means the shop
     * has never been asked, and everything is on: every branch that existed
     * before this migration is in that state, and having them go dark on
     * upgrade would be the worst possible reading of "not configured". An
     * empty array means somebody was asked and unticked every box, which is
     * a different answer and is honoured as one.
     */
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->json('modules')->nullable()->after('block_expired_sale');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('modules');
        });
    }
};
