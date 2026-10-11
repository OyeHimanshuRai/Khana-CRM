<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Take out everything that counted what is on a shelf.
     *
     * Stock, batches, warehouses, adjustments, transfers, wastage and recipes
     * are ERP's (the architecture doc's ownership table). CRM tells ERP what
     * it sold and what the kitchen cooked, through crm.sale.* and
     * crm.kitchen.* events, and ERP does the counting. The code these tables
     * served has gone; this is for the databases that still hold them.
     *
     * Units stay. A unit here is what a dish is sold in - a plate, a kilo -
     * and the till needs it to know whether 1.5 is a quantity it can bill.
     *
     * Also removed:
     *
     *   products      min_stock, reorder_level, track_batches,
     *                 is_made_to_order, is_ingredient
     *   product_shop  min_stock, reorder_level
     *   shops         allow_negative_stock, block_expired_sale,
     *                 recipe_deduction
     *   order_items   recipe_consumed_at
     *
     * Ingredient rows are archived first, not deleted: they were never sold,
     * but an archived row keeps any id something else might still point at.
     * Their permissions go, and so do the low-stock alerts nothing will raise
     * or clear again.
     *
     * Guarded throughout, so it is a no-op on a database already past it.
     * Irreversible on purpose: there is no code left for a schema to serve.
     */
    public function up(): void
    {
        if (Schema::hasColumn('products', 'is_ingredient')) {
            DB::table('products')
                ->where('is_ingredient', true)
                ->whereNull('deleted_at')
                ->update(['deleted_at' => now(), 'is_active' => false]);
        }

        Schema::withoutForeignKeyConstraints(function () {
            foreach ([
                'stock_wastages', 'recipe_items',
                'stock_transfer_items', 'stock_transfers',
                'stock_adjustment_items', 'stock_adjustments',
                'stock_movements', 'product_stocks',
                'batches', 'warehouses',
            ] as $table) {
                Schema::dropIfExists($table);
            }
        });

        $this->dropIndex('products', ['is_made_to_order', 'is_active']);
        $this->dropIndex('products', ['is_ingredient', 'is_active']);
        $this->dropColumns('products', ['min_stock', 'reorder_level', 'track_batches', 'is_made_to_order', 'is_ingredient']);
        $this->dropColumns('product_shop', ['min_stock', 'reorder_level']);
        $this->dropColumns('shops', ['allow_negative_stock', 'block_expired_sale', 'recipe_deduction']);
        $this->dropColumns('order_items', ['recipe_consumed_at']);

        if (Schema::hasTable('alerts')) {
            DB::table('alerts')->where('type', 'low_stock')->delete();
        }

        $this->dropPermissions();
    }

    public function down(): void
    {
        // Nothing to restore: the code these tables and columns served is gone.
    }

    /** @param  list<string>  $columns */
    private function dropIndex(string $table, array $columns): void
    {
        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return;
            }
        }

        $name = strtolower($table.'_'.implode('_', $columns).'_index');

        if (collect(Schema::getIndexes($table))->contains('name', $name)) {
            Schema::table($table, fn (Blueprint $table) => $table->dropIndex($name));
        }
    }

    /** @param  list<string>  $columns */
    private function dropColumns(string $table, array $columns): void
    {
        $present = array_values(array_filter($columns, fn ($column) => Schema::hasColumn($table, $column)));

        if ($present !== []) {
            Schema::table($table, fn (Blueprint $table) => $table->dropColumn($present));
        }
    }

    /**
     * The rights to screens that no longer exist.
     *
     * The role matrix is built from config and would never show them again,
     * and a grant nobody can see or revoke is not worth keeping.
     */
    private function dropPermissions(): void
    {
        $permissions = config('permission.table_names.permissions', 'permissions');
        $pivot = config('permission.table_names.role_has_permissions', 'role_has_permissions');
        $direct = config('permission.table_names.model_has_permissions', 'model_has_permissions');

        if (! Schema::hasTable($permissions)) {
            return;
        }

        $ids = DB::table($permissions)
            ->where(function ($query) {
                foreach (['recipes', 'wastage', 'units', 'stock', 'adjustments', 'batches', 'transfers', 'warehouses'] as $submodule) {
                    $query->orWhere('name', 'like', "inventory.{$submodule}.%");
                }
            })
            ->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        // Explicit rather than trusting cascades, which SQLite may not enforce.
        foreach ([$pivot, $direct] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->whereIn('permission_id', $ids)->delete();
            }
        }

        DB::table($permissions)->whereIn('id', $ids)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
