<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The supplier account, entry by entry.
     *
     * The mirror of customer_ledgers, with the signs the other way round -
     * and they are stated here rather than left to be inferred, because
     * getting them backwards is the single easiest mistake to make in a
     * purchase ledger:
     *
     *   credit  the shop owes the supplier more  (a bill)
     *   debit   the shop owes the supplier less  (a payment, a return)
     *
     * balance_after is positive when the shop is in debt to them.
     */
    public function up(): void
    {
        Schema::create('supplier_ledgers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();

            // opening | bill | payment | return | adjustment
            $table->string('type', 20)->index();

            $table->nullableMorphs('reference');

            $table->string('description', 250)->nullable();

            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);

            $table->decimal('balance_after', 15, 2)->default(0);

            $table->dateTime('entered_at');

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name', 120)->nullable();

            $table->timestamps();

            $table->index(['supplier_id', 'entered_at', 'id']);
            $table->index(['shop_id', 'type', 'entered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_ledgers');
    }
};
