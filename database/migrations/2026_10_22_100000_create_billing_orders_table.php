<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An attempt to pay the platform for a plan.
     *
     * Separate from payment_intents on purpose: that table is a restaurant
     * taking money from its own guests, shop-scoped and settled into a
     * table's bill. This is a restaurant paying the platform, belongs to a
     * tenant, and settles into a subscription. Sharing a table would put two
     * different businesses' money behind one scope.
     */
    public function up(): void
    {
        Schema::create('billing_orders', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // The row being renewed, or replaced when the plan changes.
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->string('billing_period', 10);

            // list_price - credit = amount. All three are kept so an invoice
            // can show its arithmetic long after the plan's price changes.
            $table->decimal('list_price', 12, 2);
            $table->decimal('credit', 12, 2)->default(0);
            $table->decimal('amount', 12, 2);
            $table->string('currency', 8)->default('INR');

            $table->string('reference', 64)->unique();
            $table->string('provider', 30);
            $table->string('provider_order_id', 120)->nullable()->unique();
            $table->string('provider_payment_id', 120)->nullable()->unique();
            $table->string('status', 20)->default('pending');
            $table->string('failure_reason', 250)->nullable();
            $table->json('payload')->nullable();

            $table->foreignId('subscription_payment_id')->nullable()
                ->constrained('subscription_payments')->nullOnDelete();
            $table->foreignId('created_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_orders');
    }
};
