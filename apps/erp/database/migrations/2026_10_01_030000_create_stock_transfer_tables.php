<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock sent from one outlet of a business to another.
 *
 * A transfer belongs to two outlets at once - the one that sent it and the
 * one it is going to - so it is the one piece of paperwork here that is not
 * held under a single `outlet_id`. Both outlets see it; nobody else does.
 *
 * Between leaving and arriving the stock is on neither shelf. It is on the
 * transfer, at the cost it left at, until the other outlet says it came.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->char('tenant_id', 26)->index();
            $table->char('from_outlet_id', 26);
            $table->char('to_outlet_id', 26);
            // Numbered by the outlet that sent it.
            $table->string('number', 30);
            $table->foreignId('from_store_id')->constrained('stores');
            // The store it was put into, once it has arrived.
            $table->unsignedBigInteger('to_store_id')->nullable();
            // sent | received | cancelled
            $table->string('status', 12)->default('sent');
            $table->date('sent_on');
            $table->date('received_on')->nullable();
            $table->string('note', 500)->nullable();
            // What left, at cost.
            $table->bigInteger('value_paise')->default(0);
            // What left and did not arrive, at cost.
            $table->bigInteger('short_paise')->default(0);
            $table->foreignId('user_id')->nullable();
            $table->unsignedBigInteger('received_by')->nullable();
            $table->timestamps();

            $table->unique(['from_outlet_id', 'number']);
            $table->index(['to_outlet_id', 'status']);
            $table->index(['from_outlet_id', 'status']);
        });

        /*
        | One row for each batch that left. The batch's number and dates are
        | copied here rather than pointed at, because a batch belongs to one
        | outlet: the receiving outlet makes its own from this copy, so the
        | paneer arrives as the batch it left as.
        */
        Schema::create('stock_transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained();
            // The sending outlet's batch; 0 for stock with no batch.
            $table->unsignedBigInteger('batch_id')->default(0);
            $table->string('batch_no', 60)->nullable();
            $table->date('expiry_date')->nullable();
            $table->date('made_on')->nullable();
            $table->bigInteger('qty');
            $table->bigInteger('cost_rate')->default(0);
            $table->bigInteger('value_paise')->default(0);
            // What the other outlet found when it opened the crate.
            $table->bigInteger('received_qty')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_lines');
        Schema::dropIfExists('stock_transfers');
    }
};
