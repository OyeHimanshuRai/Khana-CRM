<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quantity on hand, per shop / warehouse / product / batch.
     *
     * This is a running total kept in step by App\Services\StockService.
     * stock_movements is the ledger it is derived from; this table exists so
     * a POS lookup is one indexed read rather than a SUM over every movement
     * the product has ever had.
     */
    public function up(): void
    {
        Schema::create('product_stocks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            // Null for products that are not batch-tracked.
            $table->foreignId('batch_id')->nullable()->constrained()->cascadeOnDelete();

            $table->decimal('quantity', 15, 3)->default(0);

            // Held for an online order that is placed but not yet picked.
            // Available = quantity - reserved.
            $table->decimal('reserved', 15, 3)->default(0);

            // Weighted average cost of what is currently on hand, maintained
            // on receipt. The basis for stock valuation and margin.
            $table->decimal('average_cost', 15, 4)->default(0);

            $table->timestamps();

            $table->index(['shop_id', 'product_id']);
            $table->index(['warehouse_id', 'product_id']);
        });

        $this->addSlotUniqueness();
    }

    /**
     * Make "one row per shop/warehouse/product/batch" a database guarantee.
     *
     * A plain unique index will not do it: SQL treats every NULL as distinct,
     * so the un-batched row for a product could silently be created twice
     * under concurrency - and StockService would then be locking one of two
     * halves of the same quantity.
     *
     * Folding NULL to 0 fixes that, but how you fold differs by engine:
     *
     *   MySQL / MariaDB  a stored generated column, because MariaDB has no
     *                    functional indexes at all.
     *   SQLite / Postgres  an index on the expression directly.
     *
     * Anything else falls back to the plain index, and StockService's
     * firstOrCreate-then-lock still keeps it correct in practice.
     */
    private function addSlotUniqueness(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement(
                'ALTER TABLE product_stocks
                    ADD COLUMN batch_key BIGINT UNSIGNED AS (COALESCE(batch_id, 0)) STORED'
            );

            DB::statement(
                'ALTER TABLE product_stocks
                    ADD UNIQUE product_stocks_unique_slot (shop_id, warehouse_id, product_id, batch_key)'
            );

            return;
        }

        if (in_array($driver, ['sqlite', 'pgsql'], true)) {
            DB::statement(
                'CREATE UNIQUE INDEX product_stocks_unique_slot
                    ON product_stocks (shop_id, warehouse_id, product_id, COALESCE(batch_id, 0))'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_stocks');
    }
};
