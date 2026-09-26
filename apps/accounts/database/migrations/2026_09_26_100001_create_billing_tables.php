<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| What the platform sells and who has bought it.
|
| A plan covers one or both products (crm, erp, suite). A subscription is one
| outlet on one plan. Entitlements are what the products are told: per
| outlet, per product, the state worked out from the subscriptions and the
| clock, kept here so a change is sent once and the nightly snapshot has an
| answer ready.
|
| Money is whole paise.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('blurb')->nullable();
            // ["crm"], ["erp"] or ["crm","erp"].
            $table->json('products');
            $table->unsignedBigInteger('monthly_price_paise');
            $table->unsignedBigInteger('yearly_price_paise')->nullable();
            $table->unsignedSmallInteger('trial_days')->default(14);
            // Named limits such as users or tables; null is unlimited.
            $table->json('limits')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained();
            // monthly | yearly
            $table->string('billing_period', 10)->default('monthly');
            $table->unsignedBigInteger('price_paise');
            $table->timestamp('starts_at');
            $table->timestamp('trial_ends_at')->nullable();
            // "Works until". Null is perpetual: an in-house account.
            $table->timestamp('ends_at')->nullable();
            $table->unsignedSmallInteger('grace_days')->default(3);
            // Set when the owner stops paying for it; takes effect at the
            // end of the paid term.
            $table->timestamp('cancelled_at')->nullable();
            // Set when a later subscription takes its products over.
            $table->timestamp('replaced_at')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['outlet_id', 'id']);
        });

        Schema::create('platform_payments', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->nullable()->unique();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            // Zero or negative records a refund or a correction; only a
            // positive amount moves the term forward.
            $table->bigInteger('amount_paise');
            // manual | bank | upi | razorpay
            $table->string('method', 20);
            $table->string('provider_reference')->nullable();
            $table->timestamp('paid_at');
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->string('product', 10);
            // trial | active | grace | suspended | ended
            $table->string('state', 12);
            $table->string('plan', 40);
            $table->json('limits');
            $table->timestamp('valid_until')->nullable();
            // When this state was last sent to the products.
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['outlet_id', 'product']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entitlements');
        Schema::dropIfExists('platform_payments');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }
};
