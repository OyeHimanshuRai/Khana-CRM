<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One business having bought one plan (SRS 2, 16, 21).
     *
     * ------------------------------------------------------------------
     * There is no status column, on purpose
     * ------------------------------------------------------------------
     *
     * The obvious design gives this table a `status` of trialing / active /
     * past_due / expired. It is the wrong one, because every value in it is
     * already implied by a date that is also stored, and the two drift the
     * moment a clock ticks: a row that says "active" with `ends_at` in the
     * past is a lie the database will happily keep telling until something
     * remembers to run.
     *
     * So the dates are the truth and the state is computed from them - see
     * App\Models\Subscription::state(). A subscription is trialing while
     * `trial_ends_at` is ahead, active while `ends_at` is ahead, past_due
     * inside the grace window, and expired after it. Cancellation is the one
     * genuine fact no date implies, so `cancelled_at` is stored.
     *
     * The practical gain: nothing has to run for the answer to be right.
     * The nightly sweep suspends tenants, but a subscription that expired
     * overnight reads as expired at one minute past midnight whether the
     * sweep ran or not.
     *
     * ------------------------------------------------------------------
     * History is kept by keeping rows
     * ------------------------------------------------------------------
     *
     * Changing plan ends this row and writes a new one, so a tenant's
     * subscription history is the list of its rows, oldest first. The
     * current one is simply the latest - see Tenant::subscription().
     *
     * Renewing does not write a row; it moves `ends_at` forward and records
     * the money in subscription_payments, which is where the billing
     * history belongs.
     */
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            /*
             | Restricted, not cascading. Deleting a plan somebody is paying
             | for must fail loudly rather than quietly take their
             | subscription with it. The screen offers "withdraw from sale"
             | (is_active) for the case that is actually meant.
             */
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();

            $table->string('billing_period', 10)->default('monthly');

            /*
             | Frozen at the moment of sale, not read from the plan.
             |
             | A plan's price changes; what this business agreed to pay does
             | not, until somebody renegotiates it. Reading the live price
             | would silently re-price every existing subscriber the day the
             | catalogue is edited, which is the sort of thing that ends up
             | in a complaint rather than an invoice.
             */
            $table->decimal('price', 12, 2)->default(0);
            $table->string('currency', 8)->default('INR');

            /* --------------------------------------------------------- dates */

            $table->timestamp('starts_at')->useCurrent();

            $table->timestamp('trial_ends_at')->nullable();

            /*
             | When the paid term runs out. Null is perpetual - the answer
             | for an install that is not really a SaaS, and for the
             | in-house plan a platform gives itself.
             */
            $table->timestamp('ends_at')->nullable();

            /*
             | How long after `ends_at` the business keeps working.
             |
             | Locking a restaurant out of its own till at midnight on the
             | day an invoice fell due is a way to lose a customer over an
             | accounts department's weekend. Three days of visible warning
             | costs the platform nothing and is what the business will
             | remember.
             */
            $table->unsignedSmallInteger('grace_days')->default(3);

            $table->timestamp('cancelled_at')->nullable();

            /* --------------------------------------------------- operational */

            $table->string('note', 255)->nullable();

            $table->foreignId('created_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The sweep's query: everything whose term has run out.
            $table->index('ends_at');

            // Tenant::subscription() takes the latest per tenant.
            $table->index(['tenant_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
