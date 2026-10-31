<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A recipe belongs to a branch, so its uniqueness has to as well.
     *
     * `recipe_items` rows are shop-scoped (every query reads through the shop
     * in context), but the unique index was on dish + size + ingredient with
     * no shop in it. For a company with several branches that meant the first
     * branch to write a recipe owned it for the whole company: a second branch
     * could not record its own version of the same dish, and so never
     * consumed any ingredient when it cooked it.
     *
     * The index is rebuilt with the shop in it. It is also made to mean what
     * it says for the ordinary case, a recipe that applies to the dish at any
     * size: `product_variant_id` is NULL there, and a unique index treats
     * every NULL as different, so "flour listed twice" was never actually
     * refused. A generated key that turns NULL into 0 closes that, the same
     * way product_stocks does for a missing batch.
     *
     * Existing rows already satisfy the stricter rule. If an install somehow
     * holds two identical rows, the migration stops and says so rather than
     * deleting somebody's recipe: two rows for the same ingredient is a recipe
     * that double-counts, and which one is right is a human decision.
     */
    public function up(): void
    {
        $this->refuseIfDuplicates();

        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            // The dish's foreign key was leaning on the old unique index for
            // its own index; give it one before that index goes.
            Schema::table('recipe_items', fn (Blueprint $table) => $table->index('product_id', 'recipe_items_product_id_index'));

            Schema::table('recipe_items', fn (Blueprint $table) => $table->dropUnique('recipe_items_unique_component'));

            DB::statement('ALTER TABLE recipe_items ADD COLUMN variant_key BIGINT UNSIGNED AS (COALESCE(product_variant_id, 0)) STORED');
            DB::statement('ALTER TABLE recipe_items ADD UNIQUE recipe_items_unique_component (shop_id, product_id, variant_key, ingredient_id)');

            return;
        }

        Schema::table('recipe_items', fn (Blueprint $table) => $table->dropUnique('recipe_items_unique_component'));

        DB::statement(
            'CREATE UNIQUE INDEX recipe_items_unique_component
                ON recipe_items (shop_id, product_id, COALESCE(product_variant_id, 0), ingredient_id)'
        );
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            Schema::table('recipe_items', fn (Blueprint $table) => $table->dropUnique('recipe_items_unique_component'));
            DB::statement('ALTER TABLE recipe_items DROP COLUMN variant_key');
            Schema::table('recipe_items', function (Blueprint $table) {
                $table->unique(['product_id', 'product_variant_id', 'ingredient_id'], 'recipe_items_unique_component');
                $table->dropIndex('recipe_items_product_id_index');
            });

            return;
        }

        DB::statement('DROP INDEX recipe_items_unique_component');
        Schema::table('recipe_items', fn (Blueprint $table) => $table->unique(['product_id', 'product_variant_id', 'ingredient_id'], 'recipe_items_unique_component'));
    }

    private function refuseIfDuplicates(): void
    {
        $duplicates = DB::table('recipe_items')
            ->selectRaw('shop_id, product_id, COALESCE(product_variant_id, 0) as variant, ingredient_id, COUNT(*) as copies')
            ->groupBy('shop_id', 'product_id', DB::raw('COALESCE(product_variant_id, 0)'), 'ingredient_id')
            ->havingRaw('COUNT(*) > 1')
            ->limit(5)
            ->get();

        if ($duplicates->isNotEmpty()) {
            $first = $duplicates->first();

            throw new RuntimeException(sprintf(
                'recipe_items holds the same ingredient twice for one dish and size (for example shop %d, dish %d, ingredient %d). '
                .'Remove the repeated row so the recipe counts it once, then run the migration again.',
                $first->shop_id, $first->product_id, $first->ingredient_id,
            ));
        }
    }
};
