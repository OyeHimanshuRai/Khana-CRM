<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Turn the product catalogue into a restaurant menu (§8).
     *
     * The table stays `products`, because that is what it still is: a thing
     * sold, stocked, taxed and reported on. Renaming it to `menu_items` would
     * touch every service, every report and every foreign key in the codebase
     * to change a noun.
     *
     * What changes is what hangs off a row:
     *
     *   - a veg/non-veg mark, which in India is closer to a legal requirement
     *     than a nicety (FSSAI green and brown dots)
     *   - per-channel prices, because a dish is routinely dearer on a
     *     delivery app than across the counter
     *   - an availability window, so a breakfast menu stops being orderable
     *     at eleven without anybody switching it off
     *   - a sold-out flag that a kitchen can throw in one tap mid-service
     *   - how long it takes to cook, which the kitchen display needs
     *
     * The agri columns from the catalogue's first life come off in the same
     * breath. `crop`, `pest_disease`, `dosage` and the two advisory text
     * fields were written for a pesticide counter and mean nothing on a menu;
     * leaving them would keep them on the product form forever.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            /* ------------------------------------------------- what it is */

            /*
             | veg | non_veg | egg | vegan | jain
             |
             | Nullable, and null is not "unknown veg": it is a row that is
             | not food at all - a bottle of water, a paper bag, a service
             | charge line. Those must not get a green dot, and defaulting
             | them to veg would put one there.
             |
             | See App\Models\Product::FOOD_TYPES.
             */
            $table->string('food_type', 12)->nullable()->after('category_id');

            /*
             | 0 = not spicy, 1 = mild, 2 = medium, 3 = hot.
             |
             | An integer rather than a flag because §8 asks for "Spicy" as a
             | tag and every menu in the country prints it as a scale.
             */
            $table->unsignedTinyInteger('spice_level')->default(0)->after('food_type');

            /*
             | Anything else the restaurant wants on the card: "Chef's
             | special", "Contains nuts", "Gluten free". A JSON array rather
             | than a table, because these are labels printed next to a dish
             | and nothing joins on them.
             */
            $table->json('food_tags')->nullable()->after('spice_level');

            // "Serves 2". Printed on the menu so a table can order sensibly.
            $table->unsignedTinyInteger('serves')->nullable()->after('food_tags');

            /*
             | How long the kitchen needs, in minutes. Read by the KDS to age
             | a ticket and by the customer menu to set an expectation.
             | Nullable because a bottled drink has no prep time and showing
             | "0 min" would look like a bug.
             */
            $table->unsignedSmallInteger('prep_minutes')->nullable()->after('serves');

            /* ---------------------------------------------------- pricing */

            /*
             | Per-channel prices (§8). All nullable, and null means "use
             | selling_price" rather than "free" - a restaurant that charges
             | the same everywhere should not have to fill in three fields.
             |
             | Resolved in one place: Product::priceFor($channel).
             */
            $table->decimal('dine_in_price', 15, 4)->nullable()->after('selling_price');
            $table->decimal('takeaway_price', 15, 4)->nullable()->after('dine_in_price');
            $table->decimal('delivery_price', 15, 4)->nullable()->after('takeaway_price');

            /* ----------------------------------------------- availability */

            /*
             | The instant Sold Out toggle (§8).
             |
             | `sold_out_until` is what makes it usable: a kitchen that runs
             | out of prawns at nine wants them back on the menu tomorrow,
             | not to remember to un-tick something at midnight. Null with
             | the flag set means sold out until somebody says otherwise.
             */
            $table->boolean('is_sold_out')->default(false)->after('is_active');
            $table->timestamp('sold_out_until')->nullable()->after('is_sold_out');

            /*
             | The serving window (§8). Both null means all day, which is
             | what almost every row will be.
             |
             | Times without a date: this is "07:00 to 11:00 on the days
             | below", not an event. Stored in the shop's own wall time -
             | breakfast ends at eleven wherever the server is.
             */
            $table->time('available_from')->nullable()->after('sold_out_until');
            $table->time('available_to')->nullable()->after('available_from');

            /*
             | Days of the week it is served, as ISO numbers (1 = Monday).
             | Null means every day. A JSON array rather than seven booleans,
             | because nothing queries "all the Tuesday dishes".
             */
            $table->json('available_days')->nullable()->after('available_to');

            /*
             | An index for the one query the customer menu runs on every
             | page load: the sellable rows of one category.
             */
            $table->index(['is_active', 'is_sold_out']);
        });

        /*
         | The agri catalogue's advisory fields. Dropped rather than left,
         | because a column on `products` is a field on the product form and
         | a column in every export - it does not stay out of the way.
         */
        $this->dropColumns('products', [
            'crop', 'pest_disease', 'technical_name', 'dosage',
            'usage_instructions', 'safety_information',
        ]);

        /*
         | Sub-categories (§8). A menu is two deep almost everywhere -
         | "Main Course > Indian Breads" - and one flat list makes a card of
         | ninety dishes unreadable.
         |
         | Self-referencing and restricted on delete: removing a parent that
         | still has children would orphan them into the top level silently.
         | The controller refuses instead.
         */
        Schema::table('categories', function (Blueprint $table) {
            $table->foreignId('parent_id')
                ->nullable()
                ->after('id')
                ->constrained('categories')
                ->restrictOnDelete();

            $table->index(['parent_id', 'sort_order']);
        });
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function dropColumns(string $table, array $columns): void
    {
        $present = array_values(array_filter(
            $columns,
            fn (string $column) => Schema::hasColumn($table, $column),
        ));

        if ($present === []) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($present) {
            $blueprint->dropColumn($present);
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['is_active', 'is_sold_out']);

            $table->dropColumn([
                'food_type', 'spice_level', 'food_tags', 'serves', 'prep_minutes',
                'dine_in_price', 'takeaway_price', 'delivery_price',
                'is_sold_out', 'sold_out_until',
                'available_from', 'available_to', 'available_days',
            ]);
        });

        // The agri columns are not put back. Their data went with them, and
        // an empty column of the right name would be worse than its absence.
    }
};
