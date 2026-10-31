<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the platform has actually been paid (SRS 2, 21).
     *
     * The billing history. One row per payment received against a
     * subscription, each one naming the term it bought, so "why does this
     * account run until March" has an answer that is a record rather than a
     * recollection.
     *
     * ------------------------------------------------------------------
     * Append-only
     * ------------------------------------------------------------------
     *
     * Nothing here is edited and nothing is deleted. A payment taken in
     * error is corrected by recording a negative one against the same
     * subscription, which leaves both halves of the mistake visible. That is
     * the same rule the stock ledger and the wastage log follow, for the
     * same reason: a books entry that can be quietly altered is not a books
     * entry.
     *
     * ------------------------------------------------------------------
     * Not the same thing as a guest's payment
     * ------------------------------------------------------------------
     *
     * payment_intents (§11) is a diner paying a restaurant. This is a
     * restaurant paying the platform. They share no money, no gateway
     * account and no reconciliation, and merging them would put a
     * restaurant's takings and its own overheads in one ledger.
     */
    public function up(): void
    {
        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();

            /*
             | Denormalised from the subscription so the tenant's billing
             | history survives a plan change, and so the list screen can be
             | built without a join through a table it does not otherwise
             | need.
             */
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Quoted on the receipt: SUB-000123.
            $table->string('reference', 60)->unique();

            /*
             | Signed. A negative amount is a correction or a refund, and
             | summing the column gives what the platform is actually owed
             | rather than what it once hoped for.
             */
            $table->decimal('amount', 12, 2);
            $table->string('currency', 8)->default('INR');

            // manual | bank | upi | card | razorpay
            $table->string('method', 20)->default('manual');

            // The provider's own id, where one exists, for reconciliation.
            $table->string('provider_reference', 120)->nullable();

            $table->dateTime('paid_at');

            /*
             | The term this money bought. Stored rather than inferred: a
             | payment that arrives three weeks late still buys the month it
             | was for, and `ends_at` alone could never say which month that
             | was.
             */
            $table->dateTime('period_start');
            $table->dateTime('period_end');

            $table->string('note', 255)->nullable();

            $table->foreignId('recorded_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['tenant_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
    }
};
