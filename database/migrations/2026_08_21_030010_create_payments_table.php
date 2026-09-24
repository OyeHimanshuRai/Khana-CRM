<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Money in and money out.
     *
     * One table for both directions rather than two, because a payment has
     * the same shape whichever way it points and every report wants them
     * side by side. `direction` says which way; `party_type`/`party_id`
     * says who.
     *
     * Payments are never edited in place. An error is corrected by a
     * reversing payment, which is why there is no `updated amount` anywhere
     * and why the SRS's "payment adjustment" is its own permission.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();

            $table->string('number', 60);

            // in  - collected from a customer
            // out - paid to a supplier, or refunded to a customer
            $table->string('direction', 8)->default('in')->index();

            // cash | upi | card | bank | cheque | credit | wallet | adjustment
            $table->string('method', 20)->index();

            /*
             | Who the money moved with. A morph rather than two nullable FKs
             | so the ledger can be read without knowing which kind of party
             | a row belongs to.
             */
            $table->nullableMorphs('party');
            $table->string('party_name', 150)->nullable();

            // What it settles, when it settles one thing: an invoice, a
            // purchase bill. Null for an on-account payment.
            $table->nullableMorphs('reference');

            $table->decimal('amount', 15, 2);

            $table->dateTime('paid_at');

            // UPI reference, cheque number, card's last four - whatever the
            // shop will be asked to quote when the customer disputes it.
            $table->string('transaction_ref', 120)->nullable();
            $table->string('bank_name', 120)->nullable();
            $table->date('cheque_date')->nullable();

            // pending | cleared | bounced | cancelled
            // Cheques are the reason this exists: taken today, cleared next
            // week, and sometimes not at all.
            $table->string('status', 20)->default('cleared')->index();

            $table->text('notes')->nullable();

            // Set on the reversal, pointing at what it reverses.
            $table->foreignId('reverses_payment_id')->nullable()
                ->constrained('payments')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name', 120)->nullable();

            $table->timestamps();

            $table->unique(['shop_id', 'number']);
            $table->index(['shop_id', 'paid_at']);
            $table->index(['shop_id', 'direction', 'method', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
