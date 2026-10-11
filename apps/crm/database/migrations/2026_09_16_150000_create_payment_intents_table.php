<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One attempt to pay online (§11).
     *
     * ---------------------------------------------------------------------
     * Why this is not a Payment
     * ---------------------------------------------------------------------
     *
     * `payments` is the shop's record of money received: it hangs off an
     * invoice, it is what the ledger and the day-close read, and it exists
     * only once money has actually arrived.
     *
     * This is the provider's side, and it exists *before* that - from the
     * moment a guest taps Pay. Most of these rows never become a payment at
     * all: somebody opened a checkout, changed their mind, and closed it.
     * Folding that into `payments` would put abandoned attempts into the
     * ledger, which is precisely what a ledger must not contain.
     *
     * The two are linked once, at capture, and thereafter drift apart on
     * purpose: a refund, a chargeback or a settlement delay happens to one and
     * not the other.
     *
     * ---------------------------------------------------------------------
     * Polymorphic, because what is owed for varies
     * ---------------------------------------------------------------------
     *
     * A table's sitting today; a web order tomorrow; a subscription after
     * that. All three are "a thing somebody owes money for", and none of them
     * is a natural parent for the other two.
     */
    public function up(): void
    {
        Schema::create('payment_intents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();

            $table->string('payable_type', 190);
            $table->unsignedBigInteger('payable_id');

            /*
             | Our own reference, echoed back by the provider on every event.
             | Deliberately not the primary key: a sequential id printed on a
             | receipt tells a stranger how many payments this restaurant has
             | taken.
             */
            $table->string('reference', 64)->unique();

            $table->string('provider', 30);

            /*
             | The provider's order and payment ids. The order id is what a
             | webhook arrives holding, so it is indexed - that lookup happens
             | on the hot path of every single payment.
             */
            $table->string('provider_order_id', 120)->nullable();
            $table->string('provider_payment_id', 120)->nullable();

            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('INR');

            $table->string('status', 20)->default('pending');

            // Whatever the provider handed back for the browser to use. Kept
            // so a guest returning to a half-finished checkout meets the same
            // provider order rather than a fresh one.
            $table->json('payload')->nullable();

            $table->string('failure_reason', 250)->nullable();

            /*
             | The Payment row this became, once it became one. Nullable
             | forever for the many that never do.
             */
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('paid_at')->nullable();

            // After this an abandoned attempt is replaced rather than resumed,
            // so nobody is handed a provider order that has gone stale.
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->index(['payable_type', 'payable_id']);
            $table->index('provider_order_id');
            $table->index(['shop_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_intents');
    }
};
