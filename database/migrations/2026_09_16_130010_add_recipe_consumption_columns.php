<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ingredients, and when the kitchen is deemed to have used them (§10).
     *
     * ---------------------------------------------------------------------
     * `products.is_ingredient`
     * ---------------------------------------------------------------------
     *
     * Keeps flour off the menu. An ingredient is active, stocked, purchased
     * and reported on exactly like any other product - it is simply not
     * something a guest can order, and every screen that sells things filters
     * it out.
     *
     * ---------------------------------------------------------------------
     * `shops.recipe_deduction`
     * ---------------------------------------------------------------------
     *
     * §10 asks for automatic deduction "configurable by outlet", and the
     * choice is a real one:
     *
     *   off        the outlet does not keep ingredient stock at all
     *   accepted   the cook picked the ticket up - stock leaves the store
     *              then, which is when a kitchen manager would say it did
     *   ready      the dish exists. The default, because it is the only one
     *              that is true of every ticket that reaches it
     *   served     the plate is on the table
     *
     * Default `ready`: a ticket that never gets there was never cooked, and
     * counting its ingredients would make the stock report describe food that
     * does not exist.
     *
     * ---------------------------------------------------------------------
     * `order_items.recipe_consumed_at`
     * ---------------------------------------------------------------------
     *
     * The line consumed its recipe once, at this moment. It is a stamp and
     * not a flag so the consumption report can ask when, and it is on the
     * *line* because with station routing a ticket's lines reach `ready` at
     * different times and in different rooms.
     *
     * It is never cleared. A ticket recalled because a plate came back cold
     * has still used its ingredients - recalling it does not un-cook the
     * food - and giving the stock back would make a kitchen that sends
     * nothing back look like it is losing flour.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_ingredient')
                ->default(false)
                ->after('is_made_to_order');

            // Every sellable-item query filters on this next to is_active.
            $table->index(['is_ingredient', 'is_active']);
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->string('recipe_deduction', 20)->default('ready')->after('allow_negative_stock');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->timestamp('recipe_consumed_at')->nullable()->after('kitchen_ready_at');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('recipe_consumed_at');
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('recipe_deduction');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['is_ingredient', 'is_active']);
            $table->dropColumn('is_ingredient');
        });
    }
};
