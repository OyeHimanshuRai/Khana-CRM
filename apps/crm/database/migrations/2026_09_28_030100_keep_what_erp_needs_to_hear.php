<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The facts ERP's contracts ask for that CRM was not keeping.
     *
     *   invoice_items.product_variant_id   crm.sale.recorded names the size
     *                                      sold. A dine-in bill line copied
     *                                      the size into its name and then
     *                                      forgot which size it was.
     *
     *   invoice_items.order_item_id        the kitchen line a bill line
     *                                      settles - crm.sale.recorded's
     *                                      order_line_ids. ERP consumes a
     *                                      cooked dish from the kitchen's
     *                                      bump, and the sale must say so or
     *                                      the dish is consumed twice.
     *
     *   product_variants.purchase_price    erp.cost.changed can carry a
     *                                      cost per size, and a Half and a
     *                                      Full do not cost the same.
     *
     *   cash_paid_outs.category            crm.cash.paid_out's category: the
     *                                      expense head ERP books it under.
     *
     * Both links are nullOnDelete: a size taken off the menu or an order line
     * cleared away must not take a filed invoice line with it.
     */
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->foreignId('product_variant_id')
                ->nullable()
                ->after('product_id')
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('order_item_id')
                ->nullable()
                ->after('product_variant_id')
                ->constrained()
                ->nullOnDelete();
        });

        Schema::table('product_variants', function (Blueprint $table) {
            // Null means "the dish's own cost" - see InvoiceService::costOf().
            $table->decimal('purchase_price', 15, 4)->nullable()->after('mrp');
        });

        Schema::table('cash_paid_outs', function (Blueprint $table) {
            $table->string('category', 40)->default('petty_cash')->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_item_id');
            $table->dropConstrainedForeignId('product_variant_id');
        });

        Schema::table('product_variants', fn (Blueprint $table) => $table->dropColumn('purchase_price'));

        Schema::table('cash_paid_outs', fn (Blueprint $table) => $table->dropColumn('category'));
    }
};
