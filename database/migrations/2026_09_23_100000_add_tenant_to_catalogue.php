<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The catalogue belongs to a business (SRS 4, 10).
     *
     * ------------------------------------------------------------------------
     * What was wrong
     * ------------------------------------------------------------------------
     *
     * Products, categories, brands, units and tax rates were global: one table
     * shared by every company on the install. On a single-restaurant deployment
     * that is invisible and harmless - there is one business, and it owns
     * everything in it.
     *
     * Self-serve signup ended that. A restaurant that opened its own account
     * this morning found somebody else's menu already in its Products screen,
     * could edit it, and - worse, because a guest sees it - had those dishes on
     * its own table QR menu and storefront. Transactional data was never
     * affected: invoices, customers, orders and stock all carry shop_id and
     * were scoped from the start. It was the masters that had no owner.
     *
     * ------------------------------------------------------------------------
     * Why the company and not the branch
     * ------------------------------------------------------------------------
     *
     * A menu is shared across a group's outlets - the same Paneer Tikka is sold
     * in Ajmer Road and Tonk Road, and what differs per branch (its price, its
     * stock, whether it is listed at all) already lives on `product_shop`,
     * `product_stocks` and the price lists. Putting shop_id here would force a
     * copy of every dish per outlet and make a group's menu edit a loop.
     *
     * ------------------------------------------------------------------------
     * The unique indexes have to move with it
     * ------------------------------------------------------------------------
     *
     * `products.slug`, `products.sku`, `categories.slug`, `brands.slug` and
     * `units.code` were unique across the whole table. Left that way, the
     * second restaurant to sell a "Paneer Tikka", or to want a unit called
     * "KG", is refused because a stranger got there first - and the error names
     * a row they cannot see. Each becomes unique *within a company*.
     *
     * Barcodes are treated the same. A real EAN is globally unique in the
     * world, but two businesses stocking the same bottled water are two
     * legitimate rows here, and a shared unique index would let whoever
     * registered it first lock everybody else out of that product.
     *
     * ------------------------------------------------------------------------
     * Existing rows
     * ------------------------------------------------------------------------
     *
     * Everything already in these tables is handed to the oldest company on the
     * install - on any deployment that predates multi-tenancy that is the
     * business those rows were created by and for. Nothing is deleted and
     * nothing is copied: a row has exactly one owner afterwards.
     *
     * Rows left with a null tenant_id (an install with no companies at all)
     * stay visible to a Super Admin only, which is what TenantScope already
     * does with them.
     */
    public function up(): void
    {
        $owner = DB::table('tenants')->orderBy('id')->value('id');

        foreach ($this->tables() as $table => $uniques) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->foreignId('tenant_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('tenants')
                    // The company's rows outlive a deleted company row rather
                    // than vanishing with it: a menu is evidence of what was
                    // sold, and invoices still point at these products.
                    ->nullOnDelete();
            });

            if ($owner !== null) {
                DB::table($table)->whereNull('tenant_id')->update(['tenant_id' => $owner]);
            }

            /*
             | Now the indexes. Dropped and recreated per company, in that
             | order, so there is never a moment where nothing enforces
             | uniqueness.
             */
            foreach ($uniques as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                $this->dropUnique($table, $column);

                Schema::table($table, function (Blueprint $blueprint) use ($table, $column) {
                    $blueprint->unique(['tenant_id', $column], $this->indexName($table, $column));
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables() as $table => $uniques) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }

            /*
             | The foreign key goes first, and the order is not cosmetic.
             |
             | MySQL satisfies a foreign key with whatever index covers its
             | column, and after up() that is the new composite unique. Trying
             | to drop the index while the key still needs it fails with 1553
             | and leaves the rollback half done.
             */
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropForeign(['tenant_id']);
            });

            foreach ($uniques as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                Schema::table($table, function (Blueprint $blueprint) use ($table, $column) {
                    $blueprint->dropUnique($this->indexName($table, $column));
                });

                /*
                 | Restoring the old table-wide index can genuinely fail: by
                 | now two companies may each have a "paneer-tikka", which was
                 | the whole point of the change. Reported rather than thrown,
                 | so a rollback does not stop half way with the column
                 | already gone.
                 */
                try {
                    Schema::table($table, function (Blueprint $blueprint) use ($column) {
                        $blueprint->unique($column);
                    });
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('tenant_id');
            });
        }
    }

    /**
     * The masters this migration takes ownership of, and the columns whose
     * uniqueness has to become per-company.
     *
     * @return array<string, array<int, string>>
     */
    private function tables(): array
    {
        return [
            'products' => ['slug', 'sku', 'barcode'],
            'categories' => ['slug'],
            'brands' => ['slug'],
            'units' => ['code'],
            // No unique column of its own: a tax rate is a number and a name,
            // and two companies naming one "GST 5%" is not a collision.
            'tax_rates' => [],
        ];
    }

    private function indexName(string $table, string $column): string
    {
        return $table.'_tenant_'.$column.'_unique';
    }

    /**
     * Drop the single-column unique index on a column, whatever it is called.
     *
     * Read through `Schema::getIndexes()` rather than `SHOW INDEX`: this has
     * to run on MySQL where it is deployed and on SQLite where the tests run,
     * and a raw MySQL statement here would fail every test on the suite.
     *
     * Composite indexes are left alone. Dropping one because its first column
     * matched would take away a constraint nobody asked about.
     */
    private function dropUnique(string $table, string $column): void
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (! ($index['unique'] ?? false) || ($index['primary'] ?? false)) {
                continue;
            }

            if ($index['columns'] !== [$column]) {
                continue;
            }

            $name = $index['name'];

            Schema::table($table, function (Blueprint $blueprint) use ($name) {
                $blueprint->dropUnique($name);
            });
        }
    }
};
