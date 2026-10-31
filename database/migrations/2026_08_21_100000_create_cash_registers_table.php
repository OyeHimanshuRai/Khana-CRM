<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One cash-register session per shop per business day.
     *
     * Reconciliation only, never a source of truth for money moved - that
     * is what the payments ledger already is. This table exists to answer
     * one question at the end of the day: does what is actually in the
     * drawer match what the ledger says should be there. `expected_cash`
     * is computed once, at close, from Payment (cash, both directions) and
     * approved cash Expense rows for the day, and stored rather than kept
     * live - the till was counted against a specific figure, and that
     * figure must not silently drift if a payment is edited afterwards.
     */
    public function up(): void
    {
        Schema::create('cash_registers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->date('business_date');

            // open | closed | approved
            $table->string('status', 20)->default('open')->index();

            $table->decimal('opening_float', 15, 2)->default(0);

            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('opened_by_name', 120)->nullable();
            $table->timestamp('opened_at')->nullable();

            // Filled in at close(), not before.
            $table->decimal('expected_cash', 15, 2)->nullable();
            $table->decimal('counted_cash', 15, 2)->nullable();

            // counted - expected: positive is over, negative is short.
            $table->decimal('variance', 15, 2)->nullable();

            $table->text('notes')->nullable();

            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('closed_by_name', 120)->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('approved_by_name', 120)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('review_note')->nullable();

            $table->timestamps();

            // One session per shop per day - reopening a closed day is not
            // how a discrepancy gets fixed; a review note on approval is.
            $table->unique(['shop_id', 'business_date']);
            $table->index(['shop_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_registers');
    }
};
