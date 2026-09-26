<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the shop has asked a supplier to send.
     *
     * An intention, not an event: nothing here touches stock or the supplier
     * ledger. Goods receipts do that, and they point back at this.
     *
     * A purchase order is optional in practice - plenty of agri stock
     * arrives on a van with an invoice and no paperwork beforehand - so
     * goods_receipts.purchase_order_id is nullable and a direct receipt is a
     * first-class flow rather than a workaround.
     */
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();

            $table->string('reference', 60);
            $table->date('ordered_on');
            $table->date('expected_on')->nullable();

            // draft | pending | approved | partial | received | cancelled
            $table->string('status', 20)->default('draft')->index();

            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('tax_total', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);

            $table->text('notes')->nullable();
            $table->text('review_note')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name', 120)->nullable();

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('approved_by_name', 120)->nullable();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();

            $table->unique(['shop_id', 'reference']);
            $table->index(['shop_id', 'status', 'ordered_on']);
            $table->index(['supplier_id', 'status']);
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->string('product_name', 190);
            $table->string('sku', 60)->nullable();
            $table->string('unit_code', 12)->nullable();

            $table->decimal('quantity', 15, 3);

            // Running total of what has actually arrived against this line,
            // so "what is still outstanding" is one read rather than a join
            // across every receipt.
            $table->decimal('received_quantity', 15, 3)->default(0);

            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->decimal('tax_rate', 6, 3)->default(0);
            $table->decimal('line_total', 15, 2)->default(0);

            $table->string('note', 250)->nullable();

            $table->timestamps();

            $table->index(['purchase_order_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
    }
};
