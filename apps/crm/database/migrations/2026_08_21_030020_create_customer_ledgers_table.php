<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The customer account, entry by entry.
     *
     * Append-only, and the source of truth `customers.balance` is a cache
     * of. Every row carries the running balance after it, so a statement can
     * be printed without replaying the account from the beginning - and so a
     * disagreement can be pinned to the exact entry where the two versions
     * diverge.
     *
     * Sign convention, chosen once and stated here because everything
     * downstream depends on it:
     *
     *   debit   the customer owes the shop more  (an invoice)
     *   credit  the customer owes the shop less  (a payment, a return)
     *
     * balance_after is positive when the customer is in debt.
     */
    public function up(): void
    {
        Schema::create('customer_ledgers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            // opening | invoice | payment | return | refund | write_off | adjustment
            $table->string('type', 20)->index();

            // What caused the entry.
            $table->nullableMorphs('reference');

            $table->string('description', 250)->nullable();

            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);

            $table->decimal('balance_after', 15, 2)->default(0);

            $table->dateTime('entered_at');

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name', 120)->nullable();

            $table->timestamps();

            // The statement's natural order, and the index that makes both
            // the ledger screen and the ageing report cheap.
            $table->index(['customer_id', 'entered_at', 'id']);
            $table->index(['shop_id', 'type', 'entered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_ledgers');
    }
};
