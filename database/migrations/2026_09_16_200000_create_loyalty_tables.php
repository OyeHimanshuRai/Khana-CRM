<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Points a regular earns and spends (SRS 15, 21).
     *
     * ------------------------------------------------------------------
     * A ledger, not a balance column
     * ------------------------------------------------------------------
     *
     * The obvious design puts `loyalty_points` on the customer and adds to
     * it. It is wrong for the same reason a bank does not work that way: the
     * number is the only record, so a bug, a race or a half-finished refund
     * leaves a figure nobody can explain and nobody can reconstruct.
     *
     * Here the balance is the sum of the rows. Every point that ever existed
     * has a line saying where it came from, and "why do I have 340 points"
     * has an answer that is a statement rather than an assurance.
     *
     * `balance_after` is stored anyway - not as the truth, but so the
     * statement a guest is shown reads like a passbook rather than requiring
     * a running total to be recomputed on render.
     *
     * ------------------------------------------------------------------
     * Rates are per shop and frozen per transaction
     * ------------------------------------------------------------------
     *
     * A programme's earn rate changes. What somebody earned last March does
     * not, so the points are stored rather than recomputed from the live
     * rate - the same rule the subscription price follows.
     */
    public function up(): void
    {
        Schema::create('loyalty_programs', function (Blueprint $table) {
            $table->id();

            // One per branch. A high-street outlet and an airport counter
            // have genuinely different answers about what a point is worth.
            $table->foreignId('shop_id')->unique()->constrained()->cascadeOnDelete();

            $table->boolean('is_active')->default(false);

            $table->string('name', 90)->default('Loyalty');

            /* --------------------------------------------------- earning */

            // Points for every 100 of spend. Expressed this way because it is
            // how the offer is written on the poster.
            $table->decimal('points_per_hundred', 8, 2)->default(5);

            // Bills below this earn nothing, which is how a programme avoids
            // paying out on a cup of tea.
            $table->decimal('min_spend', 12, 2)->default(0);

            /* -------------------------------------------------- spending */

            // What one point is worth when it is spent.
            $table->decimal('redeem_value', 8, 2)->default(1);

            // Nobody may redeem below this, so a balance is worth saving up.
            $table->unsignedInteger('min_redeem_points')->default(100);

            /*
             | The most of a bill that points may pay for.
             |
             | The rule that keeps a loyalty programme from becoming a
             | discount scheme: a guest who arrives with 4,000 points should
             | still pay something, or the restaurant has sold a free meal it
             | budgeted as a discount.
             */
            $table->unsignedTinyInteger('max_redeem_percent')->default(50);

            /*
             | Months before unused points lapse. Null is never, which is a
             | real choice and not a missing value - a small restaurant that
             | expires nothing has one fewer argument at the till.
             */
            $table->unsignedSmallInteger('expiry_months')->nullable();

            $table->timestamps();
        });

        Schema::create('loyalty_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            // earn | redeem | expire | adjust
            $table->string('type', 20)->index();

            /*
             | Signed. Earning is positive, spending and expiry negative, and
             | the balance is the sum - so there is exactly one way to be
             | wrong about it rather than two.
             */
            $table->integer('points');

            // The running total after this row, for the passbook.
            $table->integer('balance_after')->default(0);

            // What earned or spent them: an Invoice, an Order.
            $table->nullableMorphs('source');

            // The money behind the points, frozen at the time.
            $table->decimal('amount', 12, 2)->nullable();

            /*
             | When these points lapse. Only ever set on an `earn` row: it is
             | the points themselves that expire, and expiry consumes the
             | oldest live ones first.
             */
            $table->dateTime('expires_at')->nullable();

            $table->string('note', 190)->nullable();

            $table->foreignId('user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['customer_id', 'created_at']);
            // The expiry sweep's query: live earn rows past their date.
            $table->index(['type', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_transactions');
        Schema::dropIfExists('loyalty_programs');
    }
};
