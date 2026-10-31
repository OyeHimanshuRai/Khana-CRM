<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Money sent back through the gateway (SRS 11, 16).
     *
     * ------------------------------------------------------------------
     * Its own table, not a status on the intent
     * ------------------------------------------------------------------
     *
     * A payment can be refunded more than once - a dish that never arrived
     * today, the service charge argued about tomorrow - and a `refunded` flag
     * on the intent can only ever record the last one. It also cannot say how
     * much is left to give back, which is the single question anybody asks
     * before pressing the button.
     *
     * So refunds are rows and the intent keeps a status that summarises them.
     * How much may still be refunded is the payment less the sum of these,
     * computed rather than stored, for the same reason the loyalty balance is.
     *
     * ------------------------------------------------------------------
     * A failed refund is kept
     * ------------------------------------------------------------------
     *
     * Razorpay refusing for "insufficient balance in your account" is the
     * commonest refund failure there is, and it is a thing the restaurant has
     * to act on. A row that vanished on failure would leave somebody certain
     * they had refunded a guest who is still out of pocket.
     */
    public function up(): void
    {
        Schema::create('payment_refunds', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();

            $table->foreignId('payment_intent_id')->constrained()->cascadeOnDelete();

            // Quoted to a guest chasing their money: RFD-000123.
            $table->string('reference', 60)->unique();

            $table->decimal('amount', 12, 2);
            $table->string('currency', 8)->default('INR');

            /*
             | Why. Required by the service rather than by the column, because
             | a refund nobody explained is the line an auditor stops at - and
             | the person who pressed the button will not remember in March.
             */
            $table->string('reason', 255)->nullable();

            // pending | processed | failed
            $table->string('status', 20)->default('pending')->index();

            // Razorpay's own id, for reconciling against their dashboard.
            $table->string('provider_refund_id', 120)->nullable();

            // The provider's wording when it refused, for the one afternoon
            // somebody needs it.
            $table->string('error', 255)->nullable();

            $table->foreignId('user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamp('refunded_at')->nullable();

            $table->timestamps();

            $table->index(['shop_id', 'created_at']);
            $table->index(['payment_intent_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_refunds');
    }
};
