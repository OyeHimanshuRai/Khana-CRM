<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add-ons and modifiers (§8): extra cheese, choice of base, no onions.
     *
     * Two tables, named as §16 names them:
     *
     *   modifiers          the question - "Choose your crust"
     *   modifier_options   the answers  - "Thin", "Thick", "Cheese burst"
     *
     * And a pivot, because the same question is asked of many dishes. A
     * restaurant defines "Choose your crust" once and attaches it to every
     * pizza; editing the price of cheese burst then changes it everywhere,
     * which is the entire reason this is not a JSON blob on the product.
     *
     * The shape of a question is `min_select` / `max_select`, which covers
     * every case a menu has without a `type` column to switch on:
     *
     *   1 / 1       a required choice   - pick your crust
     *   0 / 1       an optional choice  - add a dip?
     *   0 / n       any number of extras - toppings
     *   2 / 2       exactly two         - "choose 2 sides"
     *
     * Enforced server-side when an order line is built, never only in the
     * browser: a cart posted by hand must not be able to buy a pizza with no
     * crust or with four.
     */
    public function up(): void
    {
        Schema::create('modifiers', function (Blueprint $table) {
            $table->id();

            /*
             | Shop-scoped rather than global. "Choose your crust" is one
             | branch's question with one branch's prices, and a chain whose
             | rooftop charges more for cheese is normal.
             */
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();

            $table->string('name', 120);

            // Shown above the options: "Pick one", "Add what you like".
            $table->string('instruction', 160)->nullable();

            /*
             | How many of its options an order line must carry.
             |
             | min 0 means the question may be skipped. max null means no
             | ceiling - "as many toppings as you like" - which is different
             | from max 0 and is why it is nullable rather than 0-as-unlimited.
             */
            $table->unsignedTinyInteger('min_select')->default(0);
            $table->unsignedTinyInteger('max_select')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique(['shop_id', 'name']);
            $table->index(['shop_id', 'is_active']);
        });

        Schema::create('modifier_options', function (Blueprint $table) {
            $table->id();

            $table->foreignId('modifier_id')->constrained()->cascadeOnDelete();

            $table->string('name', 120);

            /*
             | What it adds to the line. Zero is the common case - "Thin
             | crust" costs nothing - and this is the one place a delta is
             | right, because an option has no meaning without the dish it is
             | added to.
             |
             | Signed, so "no cheese, -20" is expressible. Restaurants do
             | discount a removal.
             */
            $table->decimal('price', 15, 4)->default(0);

            /*
             | Ticked when the question is first shown. Only meaningful on a
             | question that has a default at all - most do not.
             */
            $table->boolean('is_default')->default(false);

            // An option can run out while the rest of the question stands.
            $table->boolean('is_available')->default(true);

            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique(['modifier_id', 'name']);
            $table->index(['modifier_id', 'sort_order']);
        });

        Schema::create('modifier_product', function (Blueprint $table) {
            $table->id();

            $table->foreignId('modifier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            /*
             | The order the questions are asked for *this* dish. A pizza asks
             | for its crust before its toppings; a burger attached to the
             | same toppings question may want it first.
             */
            $table->unsignedInteger('sort_order')->default(0);

            $table->unique(['modifier_id', 'product_id']);
            $table->index(['product_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modifier_product');
        Schema::dropIfExists('modifier_options');
        Schema::dropIfExists('modifiers');
    }
};
