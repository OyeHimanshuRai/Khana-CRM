<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Khana\Kit\Support\Ulid;

return new class extends Migration
{
    /**
     * Every row a crm.* event names, and that ERP may name back.
     *
     * The menu (products, product_variants) and the people and places
     * (tenants, shops, users) already carry one. These are the rest of what
     * the contracts in contracts/events/crm.*.json identify:
     *
     *   categories         crm.menu.item_upserted  category.category_id
     *   modifiers          crm.menu.item_upserted  addons[].modifier_id
     *   modifier_options   crm.menu.item_upserted  addons[].option_id, and
     *                      option_ids on every sale and kitchen line
     *   orders             crm.kitchen.*           order_id
     *   order_items        crm.kitchen.*           line_id; a sale line's
     *                      order_line_ids
     *   invoices           crm.sale.*              sale_id
     *   invoice_items      crm.sale.recorded       lines[].line_id, and the
     *                      line a return names
     *   sales_returns      crm.sale.returned       return_id
     *   cash_paid_outs     crm.cash.paid_out       paid_out_id
     *
     * Nullable, unique and backfilled, for the same SQLite reason as
     * 2026_09_27_000000_add_ulid_to_menu_items: HasPublicId gives every new
     * row one, and the unique index keeps two rows from sharing one.
     */
    private const TABLES = [
        'categories',
        'modifiers',
        'modifier_options',
        'orders',
        'order_items',
        'invoices',
        'invoice_items',
        'sales_returns',
        'cash_paid_outs',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'ulid')) {
                Schema::table($table, fn (Blueprint $t) => $t->string('ulid', 26)->nullable()->after('id')->unique());
            }

            // Row by row: a ulid is unique per row, so this cannot be one
            // UPDATE statement. A sale already on the books still has to be
            // something ERP's nightly check can ask about.
            DB::table($table)->whereNull('ulid')->orderBy('id')->select('id')
                ->chunkById(500, function ($rows) use ($table) {
                    foreach ($rows as $row) {
                        DB::table($table)->where('id', $row->id)->update(['ulid' => Ulid::generate()]);
                    }
                });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasColumn($table, 'ulid')) {
                Schema::table($table, function (Blueprint $t) use ($table) {
                    $t->dropUnique("{$table}_ulid_unique");
                    $t->dropColumn('ulid');
                });
            }
        }
    }
};
