<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Goods coming back.
     *
     * A document of its own rather than an edit to the invoice, because that
     * is what actually happened: the sale was real, and then some of it was
     * undone. The invoice keeps saying what was sold; the return says what
     * came back, when, in what condition, and what the customer got for it.
     *
     * Condition matters more here than anywhere else in the system. A sealed
     * bag goes back on the shelf; a burst one does not, and putting it there
     * would make the next stock count wrong. So each line says where its
     * goods went.
     */
    public function up(): void
    {
        Schema::create('sales_returns', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();

            // Null for a return with no bill - it happens, and refusing to
            // record it just means the stock never comes back on the system.
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            $table->string('customer_name', 150)->nullable();

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

            // Cost of what came back, so margin already booked is unwound at
            // the same figure it was booked at.
            $table->decimal('cost_total', 15, 4)->default(0);

            /*
             | How the customer was made whole:
             |
             |   refund  money handed back
             |   credit  taken off what they owe
             |   none    goods swapped, nothing changes hands
             |
             | Credit is the default because most returns are against an
             | unpaid or part-paid bill, where handing over cash would be odd.
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
            $table->index(['invoice_id', 'status']);
            $table->index(['customer_id', 'returned_on']);
        });

        Schema::create('sales_return_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sales_return_id')->constrained()->cascadeOnDelete();

            // The line it came off, when there was a bill. Lets the invoice
            // track how much of each line has come back.
            $table->foreignId('invoice_item_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained()->nullOnDelete();

            $table->string('product_name', 190);
            $table->string('sku', 60)->nullable();
            $table->string('unit_code', 12)->nullable();
            $table->string('batch_no', 80)->nullable();

            $table->decimal('quantity', 15, 3);

            /*
             | Where the goods went.
             |
             |   resalable  back on the shelf
             |   damaged    written off, never restocked
             |   expired    written off
             |
             | Only resalable quantities are received back into stock. The
             | rest is recorded so the loss is visible rather than silent.
             */
            $table->string('condition', 20)->default('resalable');

            // Copied from the invoice line so the credit matches what was
            // charged, not what the product costs today.
            $table->decimal('unit_price', 15, 4)->default(0);
            $table->decimal('taxable_value', 15, 2)->default(0);
            $table->decimal('tax_rate', 6, 3)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2)->default(0);

            $table->decimal('unit_cost', 15, 4)->default(0);

            $table->string('note', 250)->nullable();

            $table->timestamps();

            $table->index(['sales_return_id', 'product_id']);
            $table->index('invoice_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_return_items');
        Schema::dropIfExists('sales_returns');
    }
};
