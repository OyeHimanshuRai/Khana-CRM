<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Khana\Kit\Support\Ulid;

return new class extends Migration
{
    /**
     * The identifier crm.menu.* events and the erp.availability.changed
     * inbox handler match on.
     *
     * Additive and backfilled in place, not `->change()`'d to NOT NULL:
     * Product\Concerns\HasPublicId already guarantees every row created from
     * here on has one, and SQLite (the test/dev database) cannot alter a
     * column's nullability without doctrine/dbal, which this app does not
     * carry. A unique index is enough to keep two rows from ever sharing one.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('ulid', 26)->nullable()->after('id')->unique();
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->string('ulid', 26)->nullable()->after('id')->unique();
        });

        // Backfilled here rather than left to be noticed later - a size that
        // reached "Needs recipe" in ERP with no ulid is a size ERP can never
        // refer back to. chunkById rather than a single update: a ulid is
        // unique per row, so this cannot be one UPDATE statement.
        DB::table('products')->whereNull('ulid')->orderBy('id')->select('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('products')->where('id', $row->id)->update(['ulid' => Ulid::generate()]);
                }
            });

        DB::table('product_variants')->whereNull('ulid')->orderBy('id')->select('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('product_variants')->where('id', $row->id)->update(['ulid' => Ulid::generate()]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('ulid');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('ulid');
        });
    }
};
