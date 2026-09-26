<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\RecipeItem;
use App\Models\StockMovement;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\StockService;
use App\Support\CurrentShop;
use Database\Seeders\Concerns\SeedsDemoData;
use Illuminate\Database\Seeder;

/**
 * Ingredients, their opening stock, and what the dishes are made of (§10).
 *
 * Without this the recipe screen is a list of dishes with no ingredients to
 * put in them, and the consumption path has nothing to take off a shelf - so
 * neither the costing nor the deduction can be judged at all.
 *
 * The quantities are roughly real. A recipe screen seeded with 1.0 of
 * everything proves that the arithmetic runs and nothing about whether the
 * margin it produces is believable, and believable is the whole point: a
 * kitchen runs 25-35% food cost, and these come out in that range.
 *
 * Runs after DemoMenuSeeder, whose dishes it writes recipes for.
 */
class DemoRecipeSeeder extends Seeder
{
    use SeedsDemoData;

    /** name => [unit, what a kilo/litre costs the shop, opening stock] */
    private const INGREDIENTS = [
        'Basmati Rice' => ['KG', 110, 60],
        'Chicken' => ['KG', 230, 40],
        'Mutton' => ['KG', 720, 15],
        'Paneer' => ['KG', 340, 20],
        'Wheat Flour' => ['KG', 42, 80],
        'Butter' => ['KG', 520, 12],
        'Fresh Cream' => ['LTR', 220, 10],
        'Onion' => ['KG', 34, 50],
        'Tomato' => ['KG', 38, 40],
        'Cooking Oil' => ['LTR', 145, 40],
        'Ghee' => ['KG', 610, 8],
        'Garam Masala' => ['KG', 480, 5],
        'Mozzarella' => ['KG', 430, 15],
        'Pizza Base' => ['PCS', 22, 120],
        'Sugar' => ['KG', 46, 30],
        'Milk' => ['LTR', 58, 40],
        'Lemon' => ['PCS', 6, 200],
        'Tea Leaves' => ['KG', 390, 4],
        'Coffee Powder' => ['KG', 720, 3],
        'Carrot' => ['KG', 44, 20],
    ];

    /**
     * dish => [ingredient => quantity, ...]
     *
     * In the ingredient's own unit: 0.18 of Chicken is 180 grams, because
     * chicken is stocked in kilos. See the migration - there is deliberately
     * no unit conversion anywhere in this system.
     *
     * @var array<string, array<string, float>>
     */
    private const RECIPES = [
        'Butter Naan' => ['Wheat Flour' => 0.09, 'Butter' => 0.012, 'Milk' => 0.02],
        'Chicken Biryani' => ['Basmati Rice' => 0.18, 'Chicken' => 0.22, 'Onion' => 0.08, 'Ghee' => 0.02, 'Garam Masala' => 0.008],
        'Butter Chicken' => ['Chicken' => 0.24, 'Butter' => 0.03, 'Fresh Cream' => 0.05, 'Tomato' => 0.12, 'Garam Masala' => 0.006],
        'Paneer Tikka' => ['Paneer' => 0.18, 'Onion' => 0.04, 'Cooking Oil' => 0.015, 'Garam Masala' => 0.005],
        'Dal Makhani' => ['Butter' => 0.025, 'Fresh Cream' => 0.04, 'Tomato' => 0.06, 'Onion' => 0.05],
        'Margherita Pizza' => ['Pizza Base' => 1, 'Mozzarella' => 0.09, 'Tomato' => 0.06],
        'Farmhouse Pizza' => ['Pizza Base' => 1, 'Mozzarella' => 0.1, 'Tomato' => 0.07, 'Onion' => 0.04],
        'Masala Chai' => ['Tea Leaves' => 0.006, 'Milk' => 0.12, 'Sugar' => 0.012],
        'Filter Coffee' => ['Coffee Powder' => 0.012, 'Milk' => 0.15, 'Sugar' => 0.012],
        'Gajar Halwa' => ['Carrot' => 0.16, 'Milk' => 0.1, 'Sugar' => 0.05, 'Ghee' => 0.015],
        'Fresh Lime Soda' => ['Lemon' => 1, 'Sugar' => 0.02],
        'Aloo Paratha' => ['Wheat Flour' => 0.1, 'Butter' => 0.015, 'Onion' => 0.03],
        'Mutton Rogan Josh' => ['Mutton' => 0.25, 'Onion' => 0.08, 'Ghee' => 0.025, 'Garam Masala' => 0.008],
    ];

    public function __construct(private readonly StockService $stock) {}

    public function run(): void
    {
        $this->seedRandom(10);

        $shopId = CurrentShop::id();

        if ($shopId === null) {
            $this->command?->warn('  No shop in context; skipping recipes.');

            return;
        }

        $ingredients = $this->ingredients();

        if ($ingredients->isEmpty()) {
            return;
        }

        $this->openingStock($ingredients, $shopId);
        $this->recipes($ingredients, $shopId);
    }

    /**
     * @return \Illuminate\Support\Collection<string, Product>
     */
    private function ingredients(): \Illuminate\Support\Collection
    {
        $units = Unit::query()->pluck('id', 'code');
        $exempt = TaxRate::query()->where('name', 'like', 'Exempt%')->value('id')
            ?? TaxRate::query()->orderBy('rate')->value('id');

        $made = collect();
        $created = 0;

        foreach (self::INGREDIENTS as $name => [$unitCode, $cost, $opening]) {
            // firstOrCreate on the name, so a re-run does not make a second
            // "Onion" and split the kitchen's stock across two rows.
            $existing = Product::query()->where('name', $name)->first();

            if ($existing !== null) {
                $made->put($name, $existing);

                continue;
            }

            $product = Product::query()->create([
                'name' => $name,
                'slug' => Product::uniqueSlug($name),
                'sku' => Product::generateSku('ING'),
                'unit_id' => $units[$unitCode] ?? $units['PCS'] ?? null,
                'tax_rate_id' => $exempt,
                'purchase_price' => $cost,
                // Sold to nobody, so the selling price is the cost. It keeps
                // the margin report from showing a loss on a row that is never
                // on an invoice.
                'selling_price' => $cost,
                'mrp' => $cost,
                /*
                 | The two flags that make it an ingredient rather than a dish:
                 | not cooked to order, and not sold at all.
                 */
                'is_made_to_order' => false,
                'is_ingredient' => true,
                'is_active' => true,
                'is_published' => false,
                'min_stock' => max(1, (int) round($opening * 0.15)),
                'reorder_level' => max(2, (int) round($opening * 0.3)),
                'track_batches' => false,
                'short_description' => $name.', bought by the '.strtolower($unitCode).'.',
            ]);

            $made->put($name, $product);
            $created++;
        }

        $this->say(sprintf('%d ingredient(s).', $created));

        return $made;
    }

    /**
     * Put something on the shelf, so the first service has flour to cook with.
     *
     * @param  \Illuminate\Support\Collection<string, Product>  $ingredients
     */
    private function openingStock(\Illuminate\Support\Collection $ingredients, int $shopId): void
    {
        $warehouse = Warehouse::query()->where('shop_id', $shopId)->orderByDesc('is_default')->first();

        if ($warehouse === null) {
            $this->command?->warn('  No warehouse to open ingredient stock into.');

            return;
        }

        $opened = 0;

        foreach (self::INGREDIENTS as $name => [, $cost, $quantity]) {
            $product = $ingredients->get($name);

            if ($product === null) {
                continue;
            }

            // Only where the shelf is genuinely empty: a re-run must not keep
            // adding another sixty kilos of rice.
            $onHand = (float) \App\Models\ProductStock::withoutGlobalScopes()
                ->where('shop_id', $shopId)
                ->where('product_id', $product->id)
                ->sum('quantity');

            if ($onHand > 0) {
                continue;
            }

            $this->stock->receive(
                $product,
                $quantity,
                $warehouse,
                null,
                (float) $cost,
                StockMovement::OPENING,
                null,
                'Opening ingredient stock',
                $shopId,
            );

            $opened++;
        }

        if ($opened > 0) {
            $this->say(sprintf('Opening stock for %d ingredient(s).', $opened));
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<string, Product>  $ingredients
     */
    private function recipes(\Illuminate\Support\Collection $ingredients, int $shopId): void
    {
        $written = 0;

        foreach (self::RECIPES as $dishName => $components) {
            $dish = Product::query()->where('name', $dishName)->where('is_ingredient', false)->first();

            if ($dish === null) {
                continue;
            }

            // A dish that already has one is left alone: a demo seeder must
            // not overwrite a recipe somebody wrote on the screen it seeds for.
            if ($dish->recipeItems()->exists()) {
                continue;
            }

            $order = 0;

            foreach ($components as $name => $quantity) {
                $ingredient = $ingredients->get($name);

                if ($ingredient === null) {
                    continue;
                }

                RecipeItem::query()->create([
                    'shop_id' => $shopId,
                    'product_id' => $dish->id,
                    'product_variant_id' => null,
                    'ingredient_id' => $ingredient->id,
                    'quantity' => $quantity,
                    'sort_order' => $order++,
                ]);
            }

            $written++;
        }

        $this->say(sprintf('%d recipe(s).', $written));
    }
}
