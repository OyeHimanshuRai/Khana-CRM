<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sales invoices - the POS receipt and the manual invoice are the same
     * document with a different `channel`.
     *
     * Two rules shape this table:
     *
     *   1. Every tax and price figure is copied, never referenced. A slab
     *      re-rated next year must not silently restate an invoice raised
     *      today, and a product renamed must not change what the customer
     *      was handed.
     *
     *   2. Nothing is deleted. Cancellation is a status plus a reversing set
     *      of stock movements, so the number stays in the series and the
     *      audit trail stays whole.
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();

            // Null for a walk-in cash sale, which is most of them.
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            /*
             | Denormalised customer identity. An invoice has to keep
             | printing what it said at the time, even after the customer
             | record is renamed, corrected or removed.
             */
            $table->string('customer_name', 150)->nullable();
            $table->string('customer_mobile', 20)->nullable();
            $table->string('customer_gstin', 20)->nullable();
            $table->string('customer_state', 90)->nullable();
            $table->text('billing_address')->nullable();

            $table->string('number', 60);

            // pos | manual | online — the same document, raised differently.
            $table->string('channel', 20)->default('pos')->index();

            // draft | issued | paid | partial | cancelled | returned
            $table->string('status', 20)->default('issued')->index();

            $table->dateTime('invoiced_at');

            /* --------------------------------------------------------- tax */

            // Decided once, at billing time, from the shop's state versus the
            // customer's - and stored, because the answer must not change if
            // either address is later edited.
            $table->boolean('is_inter_state')->default(false);
            $table->string('place_of_supply', 90)->nullable();

            /* ------------------------------------------------------ amounts */

            $table->decimal('subtotal', 15, 2)->default(0)
                ->comment('Sum of line taxable values, after line discounts');

            $table->decimal('line_discount_total', 15, 2)->default(0);

            // Applied to the whole bill after the lines, e.g. a round-off or
            // a goodwill discount. Kept apart from line discounts because the
            // two are authorised differently.
            $table->decimal('invoice_discount', 15, 2)->default(0);
            $table->decimal('invoice_discount_percent', 6, 3)->default(0);

            $table->decimal('cgst_total', 15, 2)->default(0);
            $table->decimal('sgst_total', 15, 2)->default(0);
            $table->decimal('igst_total', 15, 2)->default(0);
            $table->decimal('cess_total', 15, 2)->default(0);
            $table->decimal('tax_total', 15, 2)->default(0);

            $table->decimal('round_off', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);

            $table->decimal('paid_total', 15, 2)->default(0);
            $table->decimal('due_total', 15, 2)->default(0);

            // What the goods cost the shop, captured at sale time. Margin
            // worked out later must not drift with the current average.
            $table->decimal('cost_total', 15, 4)->default(0);

            /* ------------------------------------------------------- credit */

            $table->boolean('is_credit')->default(false)->index();
            $table->date('due_date')->nullable()->index();

            /* --------------------------------------------------------- who */

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name', 120)->nullable();

            // Who authorised a discount beyond the configured one, when the
            // cashier could not.
            $table->foreignId('discount_approved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 250)->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            // Unique within the shop that minted it, which is the scope the
            // series runs in.
            $table->unique(['shop_id', 'number']);

            $table->index(['shop_id', 'invoiced_at']);
            $table->index(['shop_id', 'status', 'invoiced_at']);
            $table->index(['customer_id', 'status']);
            $table->index(['shop_id', 'due_date', 'due_total']);
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained()->nullOnDelete();

            /*
             | Copied, not joined. The line prints what was sold under the
             | name and code it was sold as.
             */
            $table->string('product_name', 190);
            $table->string('sku', 60)->nullable();
            $table->string('hsn_code', 20)->nullable();
            $table->string('unit_code', 12)->nullable();
            $table->string('batch_no', 80)->nullable();
            $table->date('expiry_date')->nullable();

            $table->decimal('quantity', 15, 3);

            $table->decimal('mrp', 15, 4)->default(0);

            // Before any discount, tax-exclusive.
            $table->decimal('unit_price', 15, 4)->default(0);

            $table->decimal('discount_percent', 6, 3)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);

            // quantity x unit_price - discount_amount
            $table->decimal('taxable_value', 15, 2)->default(0);

            // The slab as it stood, spelled out so a reprint never has to
            // look anything up.
            $table->decimal('tax_rate', 6, 3)->default(0);
            $table->decimal('cgst_amount', 15, 2)->default(0);
            $table->decimal('sgst_amount', 15, 2)->default(0);
            $table->decimal('igst_amount', 15, 2)->default(0);
            $table->decimal('cess_amount', 15, 2)->default(0);

            $table->decimal('line_total', 15, 2)->default(0);

            // Weighted average cost at the moment of sale.
            $table->decimal('unit_cost', 15, 4)->default(0);

            // How much of this line has come back, so a partial return does
            // not have to be recomputed from the return documents.
            $table->decimal('returned_quantity', 15, 3)->default(0);

            $table->timestamps();

            $table->index(['invoice_id', 'product_id']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
    }
};
