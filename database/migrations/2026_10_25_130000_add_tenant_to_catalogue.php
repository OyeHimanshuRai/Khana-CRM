<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The menu belongs to a restaurant, not to the platform.
     *
     * ------------------------------------------------------------------
     * Why this changed
     * ------------------------------------------------------------------
     *
     * Products, categories and brands were global: the catalogue was built
     * for one business with several branches, where every branch sells the
     * same menu and only prices and stock differ per shop. Sold as SaaS that
     * is a leak - a restaurant signing up today opened Products and found
     * another company's fifty dishes, and a guest scanning its table QR could
     * order them.
     *
     * Units and GST slabs stay global. They are shared vocabulary every
     * company reads and only the platform edits - see PlatformAbilities.
     *
     * ------------------------------------------------------------------
     * Backfill
     * ------------------------------------------------------------------
     *
     * Every existing row is handed to the oldest tenant: the catalogue
     * predates signups, so it is the original company's. Nullable rather than
     * required, because a row with no company is still possible from a
     * console script, and TenantScope shows such a row to Super Admin alone
     * rather than to everybody.
     *
     * ------------------------------------------------------------------
     * Uniqueness is per company
     * ------------------------------------------------------------------
     *
     * Two restaurants may both have a "paneer-tikka" and an SKU "PT-01".
     * The single-column unique indexes become (tenant_id, column).
     *
     * nullOnDelete: deleting a company must not be blocked by - or silently
     * delete - the sales lines that point at its products.
     */
    private const TABLES = [
        'products' => ['slug', 'sku', 'barcode'],
        'categories' => ['slug'],
        'brands' => ['slug'],
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => $columns) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('tenant_id')->nullable()->after('id')->constrained()->nullOnDelete();
            });
        }

        $owner = DB::table('tenants')->orderBy('id')->value('id');

        if ($owner !== null) {
            foreach (array_keys(self::TABLES) as $table) {
                DB::table($table)->whereNull('tenant_id')->update(['tenant_id' => $owner]);
            }
        }

        foreach (self::TABLES as $table => $columns) {
            Schema::table($table, function (Blueprint $t) use ($table, $columns) {
                foreach ($columns as $column) {
                    $t->dropUnique("{$table}_{$column}_unique");
                    $t->unique(['tenant_id', $column]);
                }
            });
        }

        /*
         | A variant's SKU was unique across the whole platform, so a second
         | restaurant typing the same "PT-H" hit a constraint error. A variant
         | has no tenant column of its own - it reaches one through its
         | product - so it is unique per product, and still indexed for the
         | scanner.
         */
        Schema::table('product_variants', function (Blueprint $t) {
            $t->dropUnique('product_variants_sku_unique');
            $t->unique(['product_id', 'sku']);
            $t->index('sku');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $t) {
            $t->dropIndex(['sku']);
            $t->dropUnique(['product_id', 'sku']);
            $t->unique('sku');
        });

        foreach (self::TABLES as $table => $columns) {
            Schema::table($table, function (Blueprint $t) use ($columns) {
                foreach ($columns as $column) {
                    $t->dropUnique(['tenant_id', $column]);
                    $t->unique($column);
                }

                $t->dropConstrainedForeignId('tenant_id');
            });
        }
    }
};
