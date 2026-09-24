<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Food a kitchen threw away (§10).
     *
     * ---------------------------------------------------------------------
     * Why this is not a stock adjustment
     * ---------------------------------------------------------------------
     *
     * `stock_adjustments` already has damage, expiry and theft among its
     * reasons, and it is the right machinery for a stock-take: a document
     * that goes draft → pending → approved and sets a slot to a *counted*
     * quantity.
     *
     * Wastage is none of those things. It is "the chef dropped a tray of
     * paneer at eight", it is a delta rather than a count, it has to hit the
     * shelf immediately, and the person recording it is holding the empty
     * tray rather than sitting at a desk waiting for an approval. Routing it
     * through a three-state document is how a kitchen stops recording it at
     * all - and unrecorded waste is the single easiest way for a restaurant's
     * food cost to be wrong by ten percent and nobody know why.
     *
     * So: a log, not a document. One row, one movement, immediately.
     *
     * ---------------------------------------------------------------------
     * The value is frozen
     * ---------------------------------------------------------------------
     *
     * `cost_value` is what the thrown-away stock actually cost the shop, at
     * the average cost of the slot it came off, captured now. Re-deriving it
     * later would let a delivery at a different price restate what last
     * month's waste was worth - and this number's whole job is to be added up
     * over a month and shown to somebody.
     */
    public function up(): void
    {
        Schema::create('stock_wastages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();

            /*
             | Usually an ingredient, sometimes a dish: a plate that came back,
             | or a tray of naan that burnt. Both are things this system counts,
             | so both can be wasted - see WastageService, which refuses only
             | the ones it cannot take off a shelf.
             */
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

            $table->decimal('quantity', 12, 4);

            $table->string('reason_code', 30);
            $table->string('note', 250)->nullable();

            $table->decimal('unit_cost', 12, 4)->default(0);
            $table->decimal('cost_value', 14, 2)->default(0);

            /*
             | Who and when, denormalised. A wastage log is read months later
             | when somebody is asking why the food cost moved, and a name that
             | disappeared with a deleted staff account would make the answer
             | unavailable exactly then.
             */
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recorded_by_name', 150)->nullable();

            $table->timestamp('wasted_at');

            $table->timestamps();

            // The two questions this log is asked: "what did we throw away
            // this month" and "how much of this do we keep throwing away".
            $table->index(['shop_id', 'wasted_at']);
            $table->index(['product_id', 'wasted_at']);
            $table->index(['shop_id', 'reason_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_wastages');
    }
};
