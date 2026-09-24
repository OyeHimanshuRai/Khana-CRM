<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a dish is made of (§10).
     *
     * ---------------------------------------------------------------------
     * An ingredient is a product
     * ---------------------------------------------------------------------
     *
     * Flour, butter and chicken are bought from suppliers, received against
     * purchase orders, counted on a shelf, costed and reported on. Every one
     * of those is something `products` already does. A second master for
     * "ingredients" would be a second place to receive stock into, a second
     * costing rule and a second set of low-stock alerts - all of them subtly
     * different from the ones that already work.
     *
     * So an ingredient is a product with `is_ingredient` set, which keeps it
     * off the menu and out of the storefront while leaving every inventory
     * screen exactly as it is.
     *
     * ---------------------------------------------------------------------
     * Why the variant matters
     * ---------------------------------------------------------------------
     *
     * A Full biryani uses more rice than a Half, and a restaurant that
     * costed both the same would price one of them wrong. `product_variant_id`
     * null means the row applies to the dish however it is sold, which is the
     * ordinary case; a row naming a variant applies only to that size and
     * replaces the null rows for it entirely - see RecipeService, which
     * decides that in one place.
     *
     * ---------------------------------------------------------------------
     * The quantity is in the ingredient's own unit
     * ---------------------------------------------------------------------
     *
     * Four decimals, because a pinch of saffron in kilograms is 0.0002 and a
     * recipe that rounded it to zero would cost the dish wrong forever.
     *
     * There is deliberately no unit conversion. `units` has no factor and
     * inventing one here would mean two places that disagree about how many
     * grams are in a kilogram. A kitchen that thinks in grams stocks its
     * flour in grams - GM and ML are both in the unit list - and the form
     * shows the ingredient's unit beside every box so nobody types 150
     * meaning grams into a field counted in kilos.
     */
    public function up(): void
    {
        Schema::create('recipe_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();

            // The dish.
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

            $table->foreignId('product_variant_id')
                ->nullable()
                ->constrained('product_variants')
                ->cascadeOnDelete();

            // What it is made of.
            $table->foreignId('ingredient_id')->constrained('products')->cascadeOnDelete();

            $table->decimal('quantity', 12, 4);

            $table->string('note', 190)->nullable();

            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            /*
             | One row per ingredient per dish per size. A recipe that listed
             | flour twice is a recipe somebody edited in two tabs, and the
             | second entry would silently double the consumption.
             */
            $table->unique(
                ['product_id', 'product_variant_id', 'ingredient_id'],
                'recipe_items_unique_component'
            );

            $table->index(['shop_id', 'product_id']);
            // "What is this ingredient used in" - the question asked before
            // anybody deletes one.
            $table->index('ingredient_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_items');
    }
};
