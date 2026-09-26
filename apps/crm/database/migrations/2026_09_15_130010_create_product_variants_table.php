<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sizes and portions: Half / Full, Small / Medium / Large, 6" / 12" (§8).
     *
     * A variant is the *same dish at a different size*, so it carries its own
     * price and its own stock code and nothing else. It is deliberately not a
     * product of its own: a menu card lists "Biryani" once with two prices,
     * and a catalogue with "Biryani (Half)" and "Biryani (Full)" as separate
     * rows reports them as two dishes and prints them as two lines.
     *
     * **Prices are absolute, not deltas.** "Full is +140" is how a spreadsheet
     * thinks; "Half 180, Full 320" is how a menu is written and how a cook
     * quotes it. A delta also quietly breaks the moment the base price moves,
     * which happens every time costs do.
     *
     * A product either has variants or it does not. When it does, the order
     * line must name one - there is no "the default size" that a cashier can
     * skip past, because that is how a table gets billed for a Full and
     * served a Half. `is_default` only decides which one the menu shows
     * selected.
     */
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            // "Half", "Full", "Large", "6 inch".
            $table->string('name', 60);

            /*
             | Its own code, for the counter and for stock. Nullable because
             | most restaurants never scan a dish, and unique-when-present so
             | the ones that do cannot point two sizes at one barcode.
             */
            $table->string('sku', 60)->nullable()->unique();

            $table->decimal('price', 15, 4);

            // What the card says it is worth, if the restaurant prints one.
            $table->decimal('mrp', 15, 4)->nullable();

            /*
             | Which size the menu opens on. Not "the one used if none is
             | chosen" - see the note above.
             */
            $table->boolean('is_default')->default(false);

            /*
             | A size can run out on its own: the large pizza bases are gone
             | but the small ones are not.
             */
            $table->boolean('is_available')->default(true);

            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            // Two sizes of one dish cannot share a name.
            $table->unique(['product_id', 'name']);
            $table->index(['product_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
