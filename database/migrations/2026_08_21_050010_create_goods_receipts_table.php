<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What actually arrived, and what the supplier billed for it.
     *
     * The receipt and the supplier's invoice are one document here rather
     * than two. In agri retail the bill comes off the van with the goods -
     * modelling them separately would mean every receipt immediately
     * followed by a bill with identical lines, and two documents to
     * reconcile where the trade has one piece of paper.
     *
     * Posting a receipt is what puts stock on the shelf and money on the
     * supplier's account. Until then it is a draft and changes nothing.
     */
    public function up(): void
    {
        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();

            // Null for a direct receipt - stock that turned up without an
            // order, which is the common case for a small shop.
            $table->foreignId('purchase_order_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->string('reference', 60);
            $table->date('received_on');

            // draft | posted | cancelled
            // Posted is the point of no return: stock has moved.
            $table->string('status', 20)->default('draft')->index();

            /* ------------------------------------------- the supplier's bill */

            $table->string('bill_number', 60)->nullable();
            $table->date('bill_date')->nullable();
            $table->date('due_date')->nullable();

            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount_total', 15, 2)->default(0);
            $table->decimal('cgst_total', 15, 2)->default(0);
            $table->decimal('sgst_total', 15, 2)->default(0);
            $table->decimal('igst_total', 15, 2)->default(0);
            $table->decimal('tax_total', 15, 2)->default(0);

            // Freight, loading, and anything else the supplier adds that is
            // not a product line. Spread across the lines when costing, so
            // the shelf price reflects what the stock really cost to get.
            $table->decimal('other_charges', 15, 2)->default(0);

            $table->decimal('round_off', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);

            $table->decimal('paid_total', 15, 2)->default(0);
            $table->decimal('due_total', 15, 2)->default(0);

            $table->boolean('is_inter_state')->default(false);

            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name', 120)->nullable();

            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('posted_by_name', 120)->nullable();
            $table->timestamp('posted_at')->nullable();

            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 250)->nullable();

            $table->timestamps();

            $table->unique(['shop_id', 'reference']);

            // A supplier billing the same number twice is nearly always a
            // duplicate entry rather than a real second invoice.
            $table->unique(['shop_id', 'supplier_id', 'bill_number'], 'goods_receipts_unique_bill');

            $table->index(['shop_id', 'status', 'received_on']);
            $table->index(['supplier_id', 'due_total']);
        });

        Schema::create('goods_receipt_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('goods_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            // Created by posting, when the product is batch-tracked.
            $table->foreignId('batch_id')->nullable()->constrained()->nullOnDelete();

            $table->string('product_name', 190);
            $table->string('sku', 60)->nullable();
            $table->string('unit_code', 12)->nullable();

            /* ---------------------------------- batch captured at receiving */

            $table->string('batch_no', 80)->nullable();
            $table->date('mfg_date')->nullable();
            $table->date('expiry_date')->nullable();

            $table->decimal('quantity', 15, 3);

            // Free stock a supplier throws in. It costs nothing but it is on
            // the shelf, so it has to be received - and it drags the
            // weighted average down, which is correct.
            $table->decimal('free_quantity', 15, 3)->default(0);

            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->decimal('discount_percent', 6, 3)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('taxable_value', 15, 2)->default(0);

            $table->decimal('tax_rate', 6, 3)->default(0);
            $table->decimal('cgst_amount', 15, 2)->default(0);
            $table->decimal('sgst_amount', 15, 2)->default(0);
            $table->decimal('igst_amount', 15, 2)->default(0);

            $table->decimal('line_total', 15, 2)->default(0);

            // unit cost after other_charges are apportioned - what the stock
            // is actually valued at.
            $table->decimal('landed_cost', 15, 4)->default(0);

            // What the shop intends to sell it for. Written back to the
            // product on posting when it differs, because a price change
            // almost always arrives with a consignment.
            $table->decimal('mrp', 15, 4)->default(0);
            $table->decimal('selling_price', 15, 4)->default(0);

            $table->string('note', 250)->nullable();

            $table->timestamps();

            $table->index(['goods_receipt_id', 'product_id']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_items');
        Schema::dropIfExists('goods_receipts');
    }
};
