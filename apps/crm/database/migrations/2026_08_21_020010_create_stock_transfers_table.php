<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stock moving between two warehouses, which may be in different shops.
     *
     * Modelled as a two-step document rather than one instant move, because
     * that is what happens physically: goods leave one branch, spend a day
     * on a van, and arrive at another. Between dispatch and receipt they are
     * in neither warehouse's usable stock, and the shortfall a one-step
     * transfer would hide is exactly what a transfer document is for.
     *
     * The from-shop owns the row (shop_id), so the sender's list is the
     * authoritative one; the receiving shop reads it through to_shop_id.
     */
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();

            // The sending shop. This is the tenant column BelongsToShop
            // filters on, so a transfer belongs to whoever raised it.
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_warehouse_id')->constrained('warehouses')->cascadeOnDelete();

            $table->foreignId('to_shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('to_warehouse_id')->constrained('warehouses')->cascadeOnDelete();

            $table->string('reference', 60);
            $table->date('transfer_date');

            // draft | pending | approved | dispatched | received | rejected | cancelled
            $table->string('status', 20)->default('draft')->index();

            $table->text('note')->nullable();

            $table->decimal('total_quantity', 15, 3)->default(0);
            $table->decimal('total_value', 15, 4)->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name', 120)->nullable();

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('approved_by_name', 120)->nullable();
            $table->timestamp('approved_at')->nullable();

            $table->timestamp('dispatched_at')->nullable();

            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('received_by_name', 120)->nullable();
            $table->timestamp('received_at')->nullable();

            $table->text('review_note')->nullable();

            $table->timestamps();

            $table->unique(['shop_id', 'reference']);
            $table->index(['shop_id', 'status', 'transfer_date']);
            $table->index(['to_shop_id', 'status']);
        });

        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained()->nullOnDelete();

            $table->decimal('quantity', 15, 3);

            // What actually turned up. Short receipts happen, and the
            // difference is a real event the receiving shop has to record
            // rather than quietly accept.
            $table->decimal('received_quantity', 15, 3)->nullable();

            $table->decimal('unit_cost', 15, 4)->default(0);

            $table->string('note', 250)->nullable();

            $table->timestamps();

            $table->index(['stock_transfer_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
    }
};
