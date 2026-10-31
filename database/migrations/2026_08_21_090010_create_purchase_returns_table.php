<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Goods going back to a supplier.
     *
     * The mirror of sales_returns (see that migration), on the other side of
     * the counter: a receipt was posted, stock landed and the supplier was
     * billed, and then some of it needed to go back - wrong item, damaged
     * on arrival, over-supplied. A document of its own rather than an edit
     * to the receipt, for the same reason a sales return is not an edit to
     * the invoice: the receipt keeps saying what arrived, this says what
     * left again, when, and what the shop got back for it.
     *
     * There is no `condition` column the way sales_return_items has one.
     * Goods a shop sends back to a supplier are, by definition, not going
     * onto anyone's shelf here - the "resalable vs written off" branch that
     * matters for a customer's return has nothing to branch on for this one.
     */
    public function up(): void
    {
        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();

            // Null for a return with no receipt on file - rare, but refusing
            // to record it just means the stock leaving is never explained.
            $table->foreignId('goods_receipt_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('supplier_name', 150)->nullable();

            $table->string('reference', 60);
            $table->date('returned_on');

            // draft | pending | approved | rejected | cancelled
            $table->string('status', 20)->default('draft')->index();

            $table->string('reason_code', 40)->nullable();
            $table->text('reason')->nullable();

            /* ------------------------------------------------- what is owed */

            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('tax_total', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);

            /*
             | How the supplier made the shop whole:
             |
             |   refund  cash came back
             |   credit  taken off what the shop owes them
             |   none    goods swapped, nothing changes hands
             |
             | Credit is the default because most returns are against a bill
             | still outstanding, where the supplier handing over cash would
             | be unusual.
             */
            $table->string('settlement', 20)->default('credit');
            $table->decimal('refund_amount', 15, 2)->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name', 120)->nullable();

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('approved_by_name', 120)->nullable();
            $table->timestamp('approved_at')->nullable();

            $table->text('review_note')->nullable();

            $table->timestamps();

            $table->unique(['shop_id', 'reference']);
            $table->index(['shop_id', 'status', 'returned_on']);
            $table->index(['goods_receipt_id', 'status']);
            $table->index(['supplier_id', 'returned_on']);
        });

        Schema::create('purchase_return_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('purchase_return_id')->constrained()->cascadeOnDelete();

            // The line it came off, when there was a receipt. Lets the
            // receipt track how much of each line has gone back.
            $table->foreignId('goods_receipt_item_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained()->nullOnDelete();

            $table->string('product_name', 190);
            $table->string('sku', 60)->nullable();
            $table->string('unit_code', 12)->nullable();
            $table->string('batch_no', 80)->nullable();

            $table->decimal('quantity', 15, 3);

            // Copied from the receipt line - what the shop actually paid,
            // landed cost included - so the credit matches what was billed,
            // not what the product costs to buy today.
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->decimal('taxable_value', 15, 2)->default(0);
            $table->decimal('tax_rate', 6, 3)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2)->default(0);

            $table->string('note', 250)->nullable();

            $table->timestamps();

            $table->index(['purchase_return_id', 'product_id']);
            $table->index('goods_receipt_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_items');
        Schema::dropIfExists('purchase_returns');
    }
};
