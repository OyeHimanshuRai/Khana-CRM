<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The inventory ledger: every reason a quantity ever changed.
     *
     * Append-only. Nothing in the application updates or deletes a row here,
     * because "why is there 3 kg less than yesterday" has to remain
     * answerable - a correction is another movement, not an edit.
     */
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained()->nullOnDelete();

            /*
             | Why the stock moved. Kept as a string rather than an enum so
             | adding a reason is a code change, not a migration - MySQL enum
             | alterations lock the table.
             */
            $table->string('type', 30)->index();

            // Signed: positive puts stock in, negative takes it out. Signing
            // the quantity rather than storing a direction flag means a
            // balance is a plain SUM.
            $table->decimal('quantity', 15, 3);

            // On-hand immediately after this movement, for the shop/warehouse
            // /product/batch slot. Lets the ledger be read without replaying
            // it from the beginning.
            $table->decimal('balance_after', 15, 3)->default(0);

            $table->decimal('unit_cost', 15, 4)->default(0);

            // What caused it: an invoice, a goods receipt, an adjustment.
            $table->nullableMorphs('reference');

            $table->string('reason', 190)->nullable();

            // Denormalised so the ledger stays readable after the account is
            // renamed or removed.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name', 120)->nullable();

            $table->timestamps();

            $table->index(['shop_id', 'product_id', 'created_at']);
            $table->index(['shop_id', 'type', 'created_at']);
            $table->index(['warehouse_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
