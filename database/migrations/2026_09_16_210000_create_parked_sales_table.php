<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A sale put to one side (SRS 6 - hold/resume).
     *
     * ------------------------------------------------------------------
     * Why a table and not the session
     * ------------------------------------------------------------------
     *
     * The obvious place for a held cart is the operator's session, and it is
     * wrong for the two reasons this feature exists at all:
     *
     *   - a cashier holds a sale, goes on break, and somebody else has to
     *     pick it up from the same till;
     *   - a till crashes, the browser is reloaded, or the shift changes -
     *     and a session dies with every one of those.
     *
     * Held sales are also the sort of thing a manager needs to see at the end
     * of the day, because a bill nobody ever resumed is either a walk-out or
     * a cashier who forgot.
     *
     * ------------------------------------------------------------------
     * The cart is stored as it was submitted
     * ------------------------------------------------------------------
     *
     * `payload` is exactly what the terminal would have posted. It is not
     * re-priced on resume and it is not an invoice: nothing here touches
     * stock, no number is minted and no ledger is written, because a held
     * sale is a note, not a document.
     *
     * The consequence is deliberate: a price that changed while the sale was
     * held is NOT picked up. Resuming gives back exactly what the customer
     * was quoted, and re-pricing somebody's bill because they waited ten
     * minutes at the counter is not something to do silently.
     */
    public function up(): void
    {
        Schema::create('parked_sales', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();

            // Quoted when a cashier asks a colleague to pick one up: "get
            // HOLD-014 up for me".
            $table->string('reference', 40);

            /*
             | What to call it on the list. A customer's name, a table, "the
             | man in the blue jacket" - whatever the cashier can find it by
             | thirty seconds later.
             */
            $table->string('label', 120)->nullable();

            $table->foreignId('customer_id')->nullable()
                ->constrained()->nullOnDelete();

            // pos | manual - so a held manual invoice resumes onto the right
            // screen rather than losing its extra fields.
            $table->string('channel', 20)->default('pos');

            /** The terminal's own payload, verbatim. */
            $table->json('payload');

            /*
             | Denormalised for the list only. Never read back into a sale -
             | the payload is the sale, and a total that disagreed with it
             | would be believed by exactly the wrong screen.
             */
            $table->decimal('total', 12, 2)->default(0);
            $table->unsignedSmallInteger('line_count')->default(0);

            // Who put it down, so the same person can be asked about it.
            $table->foreignId('user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['shop_id', 'reference']);
            $table->index(['shop_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parked_sales');
    }
};
