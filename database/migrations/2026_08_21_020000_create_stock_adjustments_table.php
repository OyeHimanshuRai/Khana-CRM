<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A stock correction, as a reviewable document.
     *
     * The SRS asks for adjustments to carry a reason and to be permission
     * gated, and for the sensitive ones to be approvable. That needs a
     * document rather than a bare movement: something has to exist in a
     * pending state, be looked at, and only then touch the shelf.
     *
     * Nothing here moves stock. Approval does, through StockService, and the
     * movements it writes point back at this row.
     */
    public function up(): void
    {
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();

            // Shop-local, human-quotable: ADJ/MAIN/2026/00001.
            $table->string('reference', 60);

            $table->date('adjustment_date');

            // draft | pending | approved | rejected | cancelled
            $table->string('status', 20)->default('draft')->index();

            /*
             | Why the count differs. Free text on top of a coarse category,
             | because "damage" and "damaged in the flood on the 3rd" are
             | both worth having and neither substitutes for the other.
             */
            $table->string('reason_code', 40)->nullable();
            $table->text('reason')->nullable();

            // Filled in when the document is applied, so the ledger and the
            // document agree on the size of the correction.
            $table->decimal('total_in', 15, 3)->default(0);
            $table->decimal('total_out', 15, 3)->default(0);
            $table->decimal('value_change', 15, 4)->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name', 120)->nullable();

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('approved_by_name', 120)->nullable();
            $table->timestamp('approved_at')->nullable();

            $table->text('review_note')->nullable();

            $table->timestamps();

            $table->unique(['shop_id', 'reference']);
            $table->index(['shop_id', 'status', 'adjustment_date']);
        });

        Schema::create('stock_adjustment_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('stock_adjustment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained()->nullOnDelete();

            // What the system thought was there when the line was written.
            // Kept so the document still reads correctly later, even after
            // the shelf has moved on.
            $table->decimal('system_quantity', 15, 3)->default(0);

            // What was actually counted.
            $table->decimal('counted_quantity', 15, 3)->default(0);

            // counted - system, stored rather than derived so a rounding
            // decision is made once.
            $table->decimal('difference', 15, 3)->default(0);

            $table->decimal('unit_cost', 15, 4)->default(0);

            $table->string('note', 250)->nullable();

            $table->timestamps();

            $table->index(['stock_adjustment_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustment_items');
        Schema::dropIfExists('stock_adjustments');
    }
};
