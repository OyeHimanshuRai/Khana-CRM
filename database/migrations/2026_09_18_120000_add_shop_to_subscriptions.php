<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A subscription is sold per outlet, not per business (SRS 2, 21).
     *
     * ------------------------------------------------------------------
     * Why this changed
     * ------------------------------------------------------------------
     *
     * The public pricing page has always said "Per outlet, billed monthly
     * or yearly" while the implementation billed a tenant once and used the
     * plan's `max_shops` as a ceiling on how many branches that one payment
     * covered. A group of three restaurants paid ₹1,999 and got three
     * outlets; the page had promised ₹1,999 each. The two cannot both be
     * right, and the one that takes money is the one that has to change.
     *
     * ------------------------------------------------------------------
     * shop_id is nullable, and that is the whole migration strategy
     * ------------------------------------------------------------------
     *
     * Null means "this row covers every shop in its tenant" - exactly what
     * every existing subscription already does. So this migration changes
     * no behaviour for anybody who has already bought something: their row
     * keeps working, their term keeps running, and nobody is locked out on
     * the morning of the deploy.
     *
     * New subscriptions name their shop. Resolution therefore reads: the
     * shop's own subscription first, then the tenant-wide row it may still
     * be sitting under, then nothing - and nothing still means unlimited,
     * because an install that predates billing must carry on working. See
     * App\Support\PlanAccess.
     *
     * The two shapes are allowed to coexist rather than one being converted
     * into the other. Converting would mean inventing a per-shop price out
     * of a tenant-wide one and splitting a paid term N ways, and there is
     * no arithmetic for that which is fair to both sides. A blanket row is
     * left alone until somebody re-subscribes its outlets deliberately.
     *
     * ------------------------------------------------------------------
     * Cascade, matching tenant_id
     * ------------------------------------------------------------------
     *
     * Deleting a shop takes its subscription with it, the same way deleting
     * a tenant already does. The row describes that outlet's billing and
     * means nothing without it; the payment history is kept by the same
     * rule on subscription_payments below.
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignId('shop_id')
                ->nullable()
                ->after('tenant_id')
                ->constrained()
                ->cascadeOnDelete();

            // Shop::subscription() takes the latest per shop, the same shape
            // as the tenant index the table already carries.
            $table->index(['shop_id', 'id']);
        });

        /*
         | The money follows the outlet it was taken for.
         |
         | Denormalised alongside subscription_id on purpose: a per-outlet
         | revenue report should not have to join through subscriptions and
         | then reason about which of them were blanket rows, and the column
         | stays correct even after a plan change writes a new subscription.
         */
        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->foreignId('shop_id')
                ->nullable()
                ->after('tenant_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->index(['shop_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->dropForeign(['shop_id']);
            $table->dropIndex(['shop_id', 'paid_at']);
            $table->dropColumn('shop_id');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropForeign(['shop_id']);
            $table->dropIndex(['shop_id', 'id']);
            $table->dropColumn('shop_id');
        });
    }
};
