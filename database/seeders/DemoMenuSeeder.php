<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Modifier;
use App\Models\ModifierOption;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Support\CurrentShop;
use Database\Seeders\Concerns\SeedsDemoData;
use Illuminate\Database\Seeder;

/**
 * A menu to work with: categories, dishes, sizes and add-ons.
 *
 * Real food rather than "Product 1..20", because every screen this seeds for
 * reads wrong without it - a veg mark on "Item 3" tells you nothing about
 * whether the mark works, and a kitchen ticket for "Product 7" cannot be
 * judged for legibility.
 *
 * The spread is deliberate and each part earns its place:
 *
 *   - veg, egg and non-veg dishes, so the marks are all exercised
 *   - two GST rates, so the tax report shows more than one row
 *   - a breakfast section with a serving window, so the availability rule
 *     has something to refuse
 *   - one dish sold out, so the menu has a greyed card
 *   - sizes on the biryanis and the drinks, add-ons on the pizzas
 *
 * Replaces the agri catalogue the product table carried in its first life.
 */
class DemoMenuSeeder extends Seeder
{
    use SeedsDemoData;

    public function run(): void
    {
        $this->seedRandom(1);

        $this->shopProfile();
        $this->kitchenStores();
        $this->brands();
        $this->categories();
        $this->dishes();
        $this->variants();
        $this->modifiers();
    }

    /**
     * Give the shop a state, because GST is decided by comparing two of them.
     *
     * A shop with no state makes every sale intra-state by definition (see
     * InvoiceService::isInterState), so the IGST column would be zero on
     * every invoice and the tax report would only ever show one shape. Only
     * fills what is blank - a real business that has typed its own address
     * keeps it.
     */
    private function shopProfile(): void
    {
        $shop = CurrentShop::get();

        if (! $shop) {
            return;
        }

        $defaults = [
            'state' => 'Rajasthan',
            'state_code' => '08',
            'city' => 'Jaipur',
        ];

        $fill = [];

        foreach ($defaults as $column => $value) {
            if (blank($shop->{$column})) {
                $fill[$column] = $value;
            }
        }

        if ($fill !== []) {
            $shop->forceFill($fill)->save();
        }
    }

    /** Where a kitchen actually keeps things. */
    private function kitchenStores(): void
    {
        $shopId = CurrentShop::id();

        $extra = [
            ['Dry Store', 'DRY', 'Grains, spices and packaging'],
            ['Cold Room', 'COLD', 'Dairy, meat and produce'],
        ];

        $created = 0;

        foreach ($extra as $index => [$name, $code, $where]) {
            $exists = Warehouse::allShops()
                ->where('shop_id', $shopId)
                ->where('code', $code)
                ->exists();

            if ($exists) {
                continue;
            }

            Warehouse::withoutEvents(fn () => Warehouse::query()->create([
                'shop_id' => $shopId,
                'name' => $name,
                'code' => $code,
                'address' => $where,
                'is_default' => false,
                'is_active' => true,
                'sort_order' => $index + 1,
            ]));

            $created++;
        }

        $this->say(sprintf('%d kitchen store(s).', $created));
    }

    /**
     * Only what a restaurant actually re-sells branded: bottled drinks.
     *
     * A kitchen does not brand its own dal makhani, so most dishes have no
     * brand at all - which is the honest state and is what the product form
     * should be seen handling.
     */
    private function brands(): void
    {
        if ($this->alreadySeeded('Brands', Brand::query()->count(), 6)) {
            return;
        }

        $brands = [
            ['Coca-Cola', 'Atlanta, USA', 'https://www.coca-colaindia.com'],
            ['PepsiCo India', 'Gurugram, India', 'https://www.pepsicoindia.co.in'],
            ['Bisleri', 'Mumbai, India', 'https://www.bisleri.com'],
            ['Amul', 'Anand, India', 'https://amul.com'],
            ['Red Bull', 'Fuschl, Austria', 'https://www.redbull.com'],
            ['Paper Boat', 'Bengaluru, India', 'https://paperboatdrinks.com'],
        ];

        foreach ($brands as $index => [$name, $maker, $website]) {
            Brand::query()->create([
                'name' => $name,
                'slug' => Brand::uniqueSlug($name),
                'manufacturer' => $maker,
                'website' => $website,
                'description' => $name.' drinks stocked at the counter.',
                'is_active' => true,
                'sort_order' => $index,
            ]);
        }

        $this->say(sprintf('%d brands.', count($brands)));
    }

    /**
     * The card, two deep.
     *
     * Sub-categories because a menu is read that way - "Main Course > Indian
     * Breads" - and ninety dishes in one flat list is a card nobody finishes.
     *
     * @var array<string, array<int, string>>
     */
    private const CARD = [
        'Starters' => ['Veg Starters', 'Non-veg Starters'],
        'Main Course' => ['Vegetarian', 'Non-vegetarian', 'Indian Breads', 'Rice & Biryani'],
        'Breakfast' => [],
        'Pizza' => [],
        'Desserts' => [],
        'Beverages' => ['Hot', 'Cold'],
    ];

    private function categories(): void
    {
        if ($this->alreadySeeded('Categories', Category::query()->count(), 12)) {
            return;
        }

        $order = 0;
        $count = 0;

        foreach (self::CARD as $parent => $children) {
            $row = Category::query()->create([
                'name' => $parent,
                'slug' => Category::uniqueSlug($parent),
                'description' => $parent.' on the menu.',
                'is_active' => true,
                'sort_order' => $order++,
            ]);

            $count++;

            foreach ($children as $childOrder => $child) {
                Category::query()->create([
                    'parent_id' => $row->id,
                    'name' => $child,
                    'slug' => Category::uniqueSlug($child),
                    'is_active' => true,
                    'sort_order' => $childOrder,
                ]);

                $count++;
            }
        }

        $this->say(sprintf('%d menu categories.', $count));
    }

    /**
     * The dishes.
     *
     * name, category, food type, spice, GST, cost, price, prep mins, serves
     *
     * Costs are real enough to make the margin report mean something: a
     * kitchen runs 25-35% food cost, and a catalogue priced at cost + 5%
     * would show every dish losing money.
     */
    private const DISHES = [
        // Starters
        ['Paneer Tikka', 'Veg Starters', 'veg', 2, 5, 118, 340, 18, 2],
        ['Hara Bhara Kebab', 'Veg Starters', 'veg', 1, 5, 82, 260, 15, 2],
        ['Crispy Corn', 'Veg Starters', 'veg', 1, 5, 62, 220, 12, 2],
        ['Chicken Tikka', 'Non-veg Starters', 'non_veg', 2, 5, 152, 420, 20, 2],
        ['Chilli Chicken', 'Non-veg Starters', 'non_veg', 3, 5, 138, 390, 18, 2],

        // Main course
        ['Dal Makhani', 'Vegetarian', 'veg', 1, 5, 78, 280, 15, 2],
        ['Paneer Butter Masala', 'Vegetarian', 'veg', 1, 5, 124, 360, 18, 2],
        ['Kadai Vegetable', 'Vegetarian', 'veg', 2, 5, 92, 300, 16, 2],
        ['Butter Chicken', 'Non-vegetarian', 'non_veg', 1, 5, 168, 460, 22, 2],
        ['Mutton Rogan Josh', 'Non-vegetarian', 'non_veg', 2, 5, 245, 620, 35, 2],
        ['Tandoori Roti', 'Indian Breads', 'veg', 0, 5, 8, 35, 6, 1],
        ['Butter Naan', 'Indian Breads', 'veg', 0, 5, 14, 60, 8, 1],
        ['Laccha Paratha', 'Indian Breads', 'veg', 0, 5, 16, 70, 9, 1],
        ['Veg Biryani', 'Rice & Biryani', 'veg', 2, 5, 96, 320, 25, 2],
        ['Chicken Biryani', 'Rice & Biryani', 'non_veg', 2, 5, 158, 440, 30, 2],
        ['Jeera Rice', 'Rice & Biryani', 'veg', 0, 5, 42, 160, 12, 2],

        // Breakfast - served in a window, see dishes()
        ['Masala Dosa', 'Breakfast', 'veg', 1, 5, 48, 180, 12, 1],
        ['Poha', 'Breakfast', 'veg', 1, 5, 26, 110, 8, 1],
        ['Aloo Paratha', 'Breakfast', 'veg', 1, 5, 34, 140, 12, 1],
        ['Masala Omelette', 'Breakfast', 'egg', 2, 5, 38, 150, 10, 1],

        // Pizza - these carry the add-on questions
        ['Margherita Pizza', 'Pizza', 'veg', 0, 5, 96, 320, 18, 2],
        ['Farmhouse Pizza', 'Pizza', 'veg', 1, 5, 132, 420, 20, 2],
        ['Chicken Tikka Pizza', 'Pizza', 'non_veg', 2, 5, 168, 480, 22, 2],

        // Desserts
        ['Gulab Jamun', 'Desserts', 'veg', 0, 5, 24, 110, 5, 2],
        ['Gajar Halwa', 'Desserts', 'veg', 0, 5, 38, 150, 6, 2],

        // Beverages
        ['Masala Chai', 'Hot', 'veg', 0, 5, 9, 40, 5, 1],
        ['Filter Coffee', 'Hot', 'veg', 0, 5, 14, 60, 6, 1],
        ['Sweet Lassi', 'Cold', 'veg', 0, 5, 26, 110, 5, 1],
        ['Fresh Lime Soda', 'Cold', 'veg', 0, 5, 16, 80, 4, 1],
        ['Bottled Water 1L', 'Cold', null, 0, 18, 12, 25, 0, null],
    ];

    private function dishes(): void
    {
        if ($this->alreadySeeded('Menu items', Product::query()->count(), 25)) {
            return;
        }

        $tax = fn (int $percent) => TaxRate::query()
            ->where('rate', $percent)
            ->where('is_active', true)
            ->firstOrFail()->id;

        $rates = [5 => $tax(5), 18 => $tax(18)];

        $plate = Unit::query()->where('code', 'PCS')->firstOrFail()->id;

        $categories = Category::query()->pluck('id', 'name');
        $bottledBrand = Brand::query()->where('name', 'Bisleri')->value('id');

        foreach (self::DISHES as $index => $dish) {
            [$name, $category, $foodType, $spice, $gst, $cost, $price, $prep, $serves] = $dish;

            $breakfast = $category === 'Breakfast';
            $bottled = $foodType === null;

            Product::query()->create([
                'name' => $name,
                'slug' => Product::uniqueSlug($name),
                'sku' => Product::generateSku($name),
                // Only the bottled line has a barcode. A kitchen does not
                // scan a dal makhani, and giving every dish one would make
                // the scanner screen look more useful than it is.
                'barcode' => $bottled ? '8901030'.str_pad((string) (100 + $index), 6, '0', STR_PAD_LEFT) : null,
                'category_id' => $categories[$category] ?? null,
                'brand_id' => $bottled ? $bottledBrand : null,
                'unit_id' => $plate,
                'tax_rate_id' => $rates[$gst],
                'hsn_code' => $bottled ? '2201' : '996331',

                'purchase_price' => $cost,
                'mrp' => $price,
                'selling_price' => $price,
                // Menu prices are what the guest hands over. A card that
                // said "₹340 + tax" is not a card anybody prints.
                'tax_inclusive' => true,
                // Delivery carries the aggregator's cut, so it is dearer.
                // Left null on bottled water, which is the same price
                // everywhere - and null means exactly that.
                'delivery_price' => $bottled ? null : round($price * 1.15),

                'food_type' => $foodType,
                'spice_level' => $spice,
                'food_tags' => $this->tagsFor($name, $index),
                'serves' => $serves,
                'prep_minutes' => $prep ?: null,

                // One dish off the menu, so a greyed card is visible.
                'is_sold_out' => $name === 'Mutton Rogan Josh',

                // Breakfast really does stop at eleven.
                'available_from' => $breakfast ? '07:00' : null,
                'available_to' => $breakfast ? '11:30' : null,

                'min_stock' => $bottled ? 24 : 0,
                'reorder_level' => $bottled ? 48 : 0,
                // Only the bottled line has a shelf life worth tracking; a
                // plate of food is cooked to order.
                'track_batches' => $bottled,
                // And the same distinction decides whether selling it moves
                // stock at all. There is no count of Butter Naan; there is a
                // very real count of bottled water. See the migration.
                'is_made_to_order' => ! $bottled,

                'short_description' => $name.', made to order.',
                'is_active' => true,
                'is_published' => true,
                'is_featured' => $index < 6,
                'sort_order' => $index,
            ]);
        }

        $this->say(sprintf('%d menu items.', count(self::DISHES)));
    }

    /**
     * A few dishes carry a label. Not all of them - a card where every line
     * is a chef's special has no chef's specials.
     *
     * @return array<int, string>|null
     */
    private function tagsFor(string $name, int $index): ?array
    {
        return match (true) {
            $name === 'Butter Chicken' => ['Chef\'s special', 'Contains dairy'],
            $name === 'Paneer Tikka' => ['Bestseller'],
            $name === 'Masala Dosa' => ['Gluten free'],
            $name === 'Farmhouse Pizza' => ['Contains dairy'],
            default => null,
        };
    }

    /**
     * Sizes, on the dishes that actually come in more than one.
     *
     * Half and Full on the biryanis, because that is how they are ordered,
     * and two sizes of pizza. Everything else has one size and correctly has
     * no variant rows at all - a menu where every dish has a "Regular"
     * variant is a menu that has misunderstood the feature.
     *
     * @var array<string, array<int, array{0: string, 1: float, 2: bool}>>
     */
    private const SIZES = [
        'Veg Biryani' => [['Half', 190, false], ['Full', 320, true]],
        'Chicken Biryani' => [['Half', 260, false], ['Full', 440, true]],
        'Margherita Pizza' => [['7 inch', 220, true], ['11 inch', 320, false]],
        'Farmhouse Pizza' => [['7 inch', 290, true], ['11 inch', 420, false]],
        'Chicken Tikka Pizza' => [['7 inch', 330, true], ['11 inch', 480, false]],
        'Masala Chai' => [['Regular', 40, true], ['Large', 60, false]],
    ];

    private function variants(): void
    {
        if ($this->alreadySeeded('Sizes', ProductVariant::query()->count(), 12)) {
            return;
        }

        $created = 0;

        foreach (self::SIZES as $dish => $sizes) {
            $product = Product::query()->where('name', $dish)->first();

            if ($product === null) {
                continue;
            }

            foreach ($sizes as $order => [$label, $price, $isDefault]) {
                ProductVariant::query()->create([
                    'product_id' => $product->id,
                    'name' => $label,
                    'price' => $price,
                    'mrp' => $price,
                    'is_default' => $isDefault,
                    'is_available' => true,
                    'sort_order' => $order,
                ]);

                $created++;
            }
        }

        $this->say(sprintf('%d sizes.', $created));
    }

    /**
     * The add-on questions, and what they are asked of.
     *
     * name, instruction, min, max, [option => price], [dishes]
     *
     * One required single-choice, one optional multi-choice and one that is
     * asked of several dishes, so the three shapes the rule can take are all
     * represented on a screen somebody can look at.
     */
    private const QUESTIONS = [
        [
            'Choose your crust', 'Pick one', 1, 1,
            ['Hand tossed' => 0, 'Thin crust' => 0, 'Cheese burst' => 70],
            ['Margherita Pizza', 'Farmhouse Pizza', 'Chicken Tikka Pizza'],
        ],
        [
            'Add extra toppings', 'Add as many as you like', 0, null,
            ['Extra cheese' => 60, 'Olives' => 40, 'Jalapeño' => 35, 'Paneer' => 70],
            ['Margherita Pizza', 'Farmhouse Pizza', 'Chicken Tikka Pizza'],
        ],
        [
            'How spicy?', 'Tell the kitchen', 1, 1,
            ['Mild' => 0, 'Medium' => 0, 'Extra spicy' => 0],
            ['Butter Chicken', 'Kadai Vegetable', 'Chilli Chicken', 'Chicken Biryani'],
        ],
        [
            'Add a side', null, 0, 2,
            ['Raita' => 50, 'Papad' => 30, 'Green salad' => 60, 'No side, thanks' => 0],
            ['Veg Biryani', 'Chicken Biryani', 'Dal Makhani'],
        ],
        [
            'Sweetness', 'For your drink', 1, 1,
            ['Normal sugar' => 0, 'Less sugar' => 0, 'No sugar' => 0],
            ['Masala Chai', 'Filter Coffee', 'Sweet Lassi'],
        ],
    ];

    private function modifiers(): void
    {
        if ($this->alreadySeeded('Add-on questions', Modifier::query()->count(), 5)) {
            return;
        }

        $options = 0;

        foreach (self::QUESTIONS as $order => [$name, $instruction, $min, $max, $choices, $dishes]) {
            /*
             | firstOrCreate, not create: a run that failed halfway leaves
             | some of these behind, and the count guard above cannot tell
             | "three of five" from "none". Re-running has to finish the job
             | rather than collide with what it already wrote.
             */
            $modifier = Modifier::query()->firstOrCreate(
                ['shop_id' => CurrentShop::id(), 'name' => $name],
                [
                    'instruction' => $instruction,
                    'min_select' => $min,
                    'max_select' => $max,
                    'is_active' => true,
                    'sort_order' => $order,
                ],
            );

            foreach (array_values($choices) as $index => $price) {
                ModifierOption::query()->firstOrCreate([
                    'modifier_id' => $modifier->id,
                    'name' => array_keys($choices)[$index],
                ], [
                    'price' => $price,
                    // The first choice is what the card opens on, and only
                    // where the question demands an answer at all.
                    'is_default' => $index === 0 && $min > 0,
                    'is_available' => true,
                    'sort_order' => $index,
                ]);

                $options++;
            }

            $ids = Product::query()->whereIn('name', $dishes)->pluck('id');

            $modifier->products()->syncWithoutDetaching(
                $ids->mapWithKeys(fn (int $id) => [$id => ['sort_order' => $order]])->all()
            );
        }

        $this->say(sprintf('%d add-on questions, %d options.', count(self::QUESTIONS), $options));
    }
}
