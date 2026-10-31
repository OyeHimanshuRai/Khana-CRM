<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A dish is made, not taken off a shelf.
     *
     * ---------------------------------------------------------------------
     * The problem this fixes
     * ---------------------------------------------------------------------
     *
     * A restaurant has no stock of Butter Naan. It has flour and butter, and
     * a cook. But `InvoiceService` issues stock for every line it bills, and
     * refuses the sale when the shelf is empty - which is correct for a shop
     * and catastrophic at a table, because by the time the bill is raised the
     * food has already been eaten. "Not enough stock" is never the right
     * answer to somebody trying to pay.
     *
     * Left alone, every restaurant would find its tables unbillable on the
     * second day of trading.
     *
     * ---------------------------------------------------------------------
     * Why a flag and not a rule
     * ---------------------------------------------------------------------
     *
     * A restaurant sells both kinds of thing. The biryani is made to order; the
     * bottle of water in the fridge is stock, and selling one really should take
     * one off the count. So this is per dish rather than per shop or per module.
     *
     * §10's recipes will hang off exactly this flag: a made-to-order dish will
     * deduct its *ingredients* when it is cooked, which is the deduction that
     * was always the right one. Until then it deducts nothing, which is honest
     * - a count of Butter Naan was never a number anybody could act on.
     *
     * ---------------------------------------------------------------------
     * The backfill
     * ---------------------------------------------------------------------
     *
     * Default false, so nothing already selling changes behaviour. Then set
     * true for rows that only the menu screens ever fill in - a food type, a
     * per-channel price, a serving window, a prep time, a kitchen station, a
     * size or an add-on. A row with any of those is a dish; a row with none of
     * them is whatever it was before this platform became a restaurant, and is
     * left alone.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_made_to_order')
                ->default(false)
                ->after('track_batches');

            // The invoice path reads it per line, so it is worth an index
            // alongside the active flag every menu query already uses.
            $table->index(['is_made_to_order', 'is_active']);
        });

        DB::table('products')
            /*
             | A product tracked lot by lot is being held on a shelf with an
             | expiry date on it, whatever else is true of it. The bottled
             | water on a restaurant's menu is exactly this, and marking it
             | made-to-order would stop its count ever going down.
             */
            ->where('track_batches', false)
            ->where(function ($q) {
                $q->whereNotNull('food_type')
                    ->orWhereNotNull('spice_level')
                    ->orWhereNotNull('prep_minutes')
                    ->orWhereNotNull('dine_in_price')
                    ->orWhereNotNull('available_from')
                    ->orWhereNotNull('kitchen_station_id')
                    ->orWhereExists(fn ($sub) => $sub->select(DB::raw(1))
                        ->from('product_variants')
                        ->whereColumn('product_variants.product_id', 'products.id'))
                    ->orWhereExists(fn ($sub) => $sub->select(DB::raw(1))
                        ->from('modifier_product')
                        ->whereColumn('modifier_product.product_id', 'products.id'));
            })
            ->update(['is_made_to_order' => true]);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['is_made_to_order', 'is_active']);
            $table->dropColumn('is_made_to_order');
        });
    }
};
