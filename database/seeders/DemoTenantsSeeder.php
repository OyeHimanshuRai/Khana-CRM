<?php

namespace Database\Seeders;

use App\Models\Batch;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Floor;
use App\Models\KitchenStation;
use App\Models\Modifier;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RecipeItem;
use App\Models\Reservation;
use App\Models\RestaurantTable;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\TaxRate;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InvoiceService;
use App\Services\ReservationService;
use App\Services\StockService;
use App\Services\TableCartService;
use App\Services\TableOrderService;
use App\Services\TableQrService;
use App\Services\TableSessionService;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use Database\Seeders\Demo\DemoData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Fills three existing companies - one per plan - with realistic, working data.
 *
 * Everything goes through the real services (stock, invoices, reservations,
 * table sittings), so the ledgers, stock balances, GST and numbering are the
 * ones the application itself would have produced, not rows typed into
 * tables. Run it twice and the second run does nothing for a company that
 * already has a menu.
 *
 *   php artisan db:seed --class=DemoTenantsSeeder
 *
 * The three owners are looked up by e-mail, and each must really be on the
 * plan named for it, so a wrong address stops the run instead of dressing up
 * somebody else's company. Their own passwords are not touched; the staff
 * accounts created here share one demo password (see PASSWORD).
 */
class DemoTenantsSeeder extends Seeder
{
    public const PASSWORD = 'Demo@12345';

    /** plan slug => owner e-mail */
    private array $owners = [
        'starter' => 'admin@gmail.com',
        'starter#2' => 'aaaaaa@gmail.com',
        'restaurant' => 'shiva420466@gmail.com',
        'chain' => 'tttttttttt@gmail.com',
    ];

    /** @var array<int, array<string, string>> filled for the final report */
    public array $accounts = [];

    /** @var array<string, int> */
    public array $summary = [];

    /** @var array<string, int> why bills were skipped, by message */
    public array $skipped = [];

    /** @var array<int, array> per-product demo options, by product id */
    private array $meta = [];

    private int $skuSeq = 0;

    public function useOwners(array $owners): static
    {
        $this->owners = $owners;

        return $this;
    }

    public function run(): void
    {
        mt_srand(20261029);

        foreach ($this->owners as $key => $email) {
            // "starter#2" means the second Starter company: same plan, a different business.
            [$plan, $variant] = array_pad(explode('#', (string) $key, 2), 2, null);

            try {
                $this->seedCompany($plan, $email, $variant);
            } catch (Throwable $e) {
                $this->say("  !! {$key}: ".$e->getMessage());
                throw $e;
            }
        }
    }

    /* ----------------------------------------------------------- blueprint */

    private function blueprint(string $plan, ?string $variant = null): array
    {
        if ($plan === 'starter' && $variant === '2') {
            return [
                'kind' => 'cafe',
                'tenant' => [
                    'name' => 'Bean & Bite Cafe', 'legal_name' => 'Bean and Bite Cafe LLP', 'code' => 'BBC',
                    'gstin' => '36AAHFB5530R1ZK', 'pan' => 'AAHFB5530R', 'phone' => '9908844123',
                ],
                'shops' => [[
                    'name' => 'Bean & Bite Cafe', 'code' => 'BBC', 'invoice_prefix' => 'BBC', 'pos_prefix' => 'BBCP',
                    'address_line1' => 'Road No. 36, Jubilee Hills', 'address_line2' => 'Opp. Peddamma Temple', 'city' => 'Hyderabad',
                    'state' => 'Telangana', 'state_code' => '36', 'pincode' => '500033', 'phone' => '9908844123',
                    'gstin' => '36AAHFB5530R1ZK', 'fssai_no' => '10124999000356', 'lat' => 17.4326, 'lng' => 78.4071,
                    'tagline' => 'Fresh coffee, warm bakes and good company',
                    'modules' => ['retail', 'pos', 'customers', 'reports', 'storefront'],
                    'sales' => 52, 'opens' => 8, 'closes' => 22,
                    'staff' => [['Aisha Siddiqui', 'Manager'], ['Sai Kiran', 'Cashier']],
                ]],
            ];
        }

        return match ($plan) {
            'starter' => [
                'kind' => 'cafe',
                'tenant' => [
                    'name' => 'Chai Point Corner', 'legal_name' => 'Chai Point Corner Pvt Ltd', 'code' => 'CPC',
                    'gstin' => '29AAKCC4821M1ZQ', 'pan' => 'AAKCC4821M', 'phone' => '9845012345',
                ],
                'shops' => [[
                    'name' => 'Chai Point Corner', 'code' => 'CPC', 'invoice_prefix' => 'CPC', 'pos_prefix' => 'CPCP',
                    'address_line1' => '14, 12th Main, HAL 2nd Stage', 'address_line2' => 'Indiranagar', 'city' => 'Bengaluru',
                    'state' => 'Karnataka', 'state_code' => '29', 'pincode' => '560038', 'phone' => '9845012345',
                    'gstin' => '29AAKCC4821M1ZQ', 'fssai_no' => '11223999000417', 'lat' => 12.9784, 'lng' => 77.6408,
                    'tagline' => 'Chai, coffee and quick bites since 2019',
                    'modules' => ['retail', 'pos', 'customers', 'reports', 'storefront'],
                    'sales' => 55, 'opens' => 8, 'closes' => 21,
                    'staff' => [['Neha Kulkarni', 'Manager'], ['Rohit Nair', 'Cashier']],
                ]],
            ],
            'restaurant' => [
                'kind' => 'restaurant',
                'tenant' => [
                    'name' => 'Spice Route Kitchen', 'legal_name' => 'Spice Route Hospitality LLP', 'code' => 'SRK',
                    'gstin' => '27AAFFS7312K1ZB', 'pan' => 'AAFFS7312K', 'phone' => '9822077441',
                ],
                'shops' => [[
                    'name' => 'Spice Route Kitchen', 'code' => 'SRK', 'invoice_prefix' => 'SRK', 'pos_prefix' => 'SRKP',
                    'address_line1' => 'Plot 21, Lane 6, North Main Road', 'address_line2' => 'Koregaon Park', 'city' => 'Pune',
                    'state' => 'Maharashtra', 'state_code' => '27', 'pincode' => '411001', 'phone' => '9822077441',
                    'gstin' => '27AAFFS7312K1ZB', 'fssai_no' => '11521999000638', 'lat' => 18.5362, 'lng' => 73.8938,
                    'tagline' => 'North Indian kitchen, tandoor and biryani',
                    'modules' => ['retail', 'pos', 'dining', 'kitchen', 'inventory', 'purchase', 'customers', 'reports', 'storefront'],
                    'sales' => 70, 'opens' => 11, 'closes' => 23, 'dining' => true,
                    'floors' => [
                        ['Ground Floor', 'GF', [[2, 14, 22], [2, 38, 22], [4, 14, 50], [4, 38, 50], [4, 62, 22], [6, 62, 50], [6, 86, 22], [8, 86, 56]]],
                        ['Rooftop', 'RT', [[2, 14, 28], [2, 14, 62], [4, 42, 28], [4, 42, 62], [6, 70, 28], [10, 76, 66]]],
                    ],
                    'stations' => [['Tandoor', 'TDR', 20, false], ['Main Kitchen', 'MK', 25, true], ['Bar & Desserts', 'BAR', 10, false]],
                    'staff' => [['Aditya Joshi', 'Manager'], ['Pooja Deshmukh', 'Cashier'], ['Sameer Khan', 'Cashier'],
                        ['Rohan Pawar', 'Captain / Waiter'], ['Ishita Kulkarni', 'Captain / Waiter'],
                        ['Chef Imran Shaikh', 'Kitchen Staff'], ['Vikram Gaikwad', 'Kitchen Staff']],
                    'live_tables' => 3, 'reservations' => 5,
                ]],
            ],
            default => [
                'kind' => 'chain',
                'tenant' => [
                    'name' => 'Urban Tadka', 'legal_name' => 'Urban Tadka Foods Pvt Ltd', 'code' => 'UTK',
                    'gstin' => '27AABCU9082L1Z3', 'pan' => 'AABCU9082L', 'phone' => '9930011220',
                ],
                'shops' => [
                    [
                        'name' => 'Urban Tadka - Andheri West', 'code' => 'UTA', 'invoice_prefix' => 'UTA', 'pos_prefix' => 'UTAP',
                        'address_line1' => 'Shop 4, Lokhandwala Complex', 'address_line2' => 'Andheri West', 'city' => 'Mumbai',
                        'state' => 'Maharashtra', 'state_code' => '27', 'pincode' => '400053', 'phone' => '9930011220',
                        'gstin' => '27AABCU9082L1Z3', 'fssai_no' => '11522999000771', 'lat' => 19.1364, 'lng' => 72.8296,
                        'tagline' => 'Fast, fresh and full of masala',
                        'sales' => 60, 'opens' => 10, 'closes' => 23, 'dining' => true, 'live_tables' => 2,
                        'floors' => [['Ground Floor', 'GF', [[2, 16, 26], [2, 40, 26], [4, 64, 26], [4, 16, 62], [4, 40, 62], [6, 70, 62]]]],
                        'staff' => [['Kunal Mehta', 'Manager'], ['Divya Shetty', 'Cashier'], ['Arun Yadav', 'Captain / Waiter'], ['Suresh Naik', 'Kitchen Staff']],
                    ],
                    [
                        'name' => 'Urban Tadka - Baner', 'code' => 'UTB', 'invoice_prefix' => 'UTB', 'pos_prefix' => 'UTBP',
                        'address_line1' => 'Ground Floor, Orchid Plaza, Baner Road', 'address_line2' => 'Baner', 'city' => 'Pune',
                        'state' => 'Maharashtra', 'state_code' => '27', 'pincode' => '411045', 'phone' => '9930011221',
                        'gstin' => '27AABCU9082L1Z3', 'fssai_no' => '11521999000802', 'lat' => 18.5590, 'lng' => 73.7868,
                        'tagline' => 'Fast, fresh and full of masala',
                        'sales' => 48, 'opens' => 10, 'closes' => 23, 'dining' => true, 'live_tables' => 1,
                        'floors' => [['Ground Floor', 'GF', [[2, 18, 28], [4, 44, 28], [4, 70, 28], [4, 30, 66], [6, 62, 66]]]],
                        'staff' => [['Mrunal Kale', 'Manager'], ['Tejas Bhosale', 'Cashier'], ['Pranav Jagtap', 'Captain / Waiter'], ['Ajay Thorat', 'Kitchen Staff']],
                    ],
                    [
                        'name' => 'Urban Tadka - Koramangala', 'code' => 'UTR', 'invoice_prefix' => 'UTR', 'pos_prefix' => 'UTRP',
                        'address_line1' => '80 Feet Road, 5th Block', 'address_line2' => 'Koramangala', 'city' => 'Bengaluru',
                        'state' => 'Karnataka', 'state_code' => '29', 'pincode' => '560095', 'phone' => '9930011222',
                        'gstin' => '29AABCU9082L1Z1', 'fssai_no' => '11223999000925', 'lat' => 12.9352, 'lng' => 77.6245,
                        'tagline' => 'Fast, fresh and full of masala',
                        'sales' => 52, 'opens' => 10, 'closes' => 23, 'dining' => true, 'live_tables' => 1,
                        'floors' => [['Ground Floor', 'GF', [[2, 18, 28], [4, 44, 28], [4, 70, 28], [4, 30, 66], [6, 62, 66]]]],
                        'staff' => [['Shreya Hegde', 'Manager'], ['Naveen Gowda', 'Cashier'], ['Karthik Rao', 'Captain / Waiter'], ['Basavaraj M', 'Kitchen Staff']],
                    ],
                ],
            ],
        };
    }

    /* ------------------------------------------------------------- company */

    private function seedCompany(string $plan, string $ownerEmail, ?string $variant = null): void
    {
        $owner = User::query()->withoutGlobalScopes()->where('email', $ownerEmail)->first()
            ?? throw new RuntimeException("No user with e-mail {$ownerEmail}.");

        $tenant = Tenant::query()->findOrFail($owner->tenant_id);
        $subscription = $tenant->subscription()->with('plan')->first();

        if ($subscription?->plan?->slug !== $plan) {
            throw new RuntimeException("{$ownerEmail} is on the '".($subscription?->plan?->slug ?? 'no')."' plan, not '{$plan}'. Stopping.");
        }

        if (Product::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->exists()) {
            $this->say("== {$plan}: {$tenant->name} already has a menu - skipped.");

            return;
        }

        $bp = $this->blueprint($plan, $variant);
        $this->say("== {$plan}: setting up {$bp['tenant']['name']} (tenant #{$tenant->id})");

        $this->clearContext();
        $this->updateTenant($tenant, $bp);

        $existingShops = Shop::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->orderBy('id')->get();
        $shops = [];

        foreach ($bp['shops'] as $i => $shopBp) {
            $shops[] = [$this->upsertShop($tenant, $existingShops[$i] ?? null, $shopBp, $plan), $shopBp];
        }

        // The owner works in every branch.
        foreach ($shops as [$shop]) {
            if (! $owner->shops()->where('shops.id', $shop->id)->exists()) {
                $owner->shops()->attach($shop->id, ['is_default' => false]);
            }
        }
        $owner->forceFill(['current_shop_id' => $shops[0][0]->id])->save();

        $products = $this->catalogue($tenant, $bp['kind']);
        $ingredients = $this->ingredients($tenant, $bp['kind']);

        foreach ($shops as [$shop, $shopBp]) {
            $this->seedShop($tenant, $shop, $shopBp, $bp['kind'], $products, $ingredients, $plan);
        }

        $this->clearContext();
        $this->summary[$variant ? "{$plan}#{$variant}" : $plan] = Product::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->count();
    }

    private function updateTenant(Tenant $tenant, array $bp): void
    {
        $first = $bp['shops'][0];
        $t = $bp['tenant'];

        $tenant->forceFill([
            'name' => $t['name'], 'legal_name' => $t['legal_name'], 'code' => $this->uniqueCode('tenants', $t['code'], $tenant->id),
            'slug' => Tenant::uniqueSlug($t['name'], $tenant->id), 'gstin' => $t['gstin'], 'pan' => $t['pan'], 'phone' => $t['phone'],
            'address_line1' => $first['address_line1'], 'address_line2' => $first['address_line2'] ?? null, 'city' => $first['city'],
            'state' => $first['state'], 'state_code' => $first['state_code'], 'pincode' => $first['pincode'],
            'latitude' => $first['lat'], 'longitude' => $first['lng'],
        ])->save();
    }

    private function upsertShop(Tenant $tenant, ?Shop $existing, array $s, string $plan): Shop
    {
        $modules = $s['modules'] ?? ($plan === 'chain'
            ? ['retail', 'pos', 'dining', 'kitchen', 'inventory', 'barcode', 'purchase', 'customers', 'reports']
            : null);

        $attributes = [
            'tenant_id' => $tenant->id, 'name' => $s['name'], 'legal_name' => $tenant->legal_name,
            'code' => $this->uniqueCode('shops', $s['code'], $existing?->id), 'gstin' => $s['gstin'], 'pan' => $tenant->pan,
            'fssai_no' => $s['fssai_no'], 'phone' => $s['phone'], 'email' => Str::slug($s['name']).'@demo-mail.example',
            'address_line1' => $s['address_line1'], 'address_line2' => $s['address_line2'] ?? null, 'city' => $s['city'],
            'state' => $s['state'], 'state_code' => $s['state_code'], 'pincode' => $s['pincode'], 'country' => 'India',
            'latitude' => $s['lat'], 'longitude' => $s['lng'], 'invoice_prefix' => $s['invoice_prefix'], 'pos_prefix' => $s['pos_prefix'],
            // Numbering counters are left alone: a shop that has billed before
            // must carry on from where it was.
            'currency' => 'INR', 'timezone' => 'Asia/Kolkata',
            'allow_negative_stock' => false, 'block_expired_sale' => true, 'recipe_deduction' => 'ready', 'requires_otp' => false,
            'modules' => $modules, 'storefront_tagline' => $s['tagline'], 'is_active' => true,
        ];

        if ($existing) {
            $existing->forceFill($attributes + ['slug' => Shop::uniqueSlug($s['name'], $existing->id)])->save();

            return $existing->refresh();
        }

        return Shop::create($attributes + ['slug' => Shop::uniqueSlug($s['name']), 'sort_order' => 0]);
    }

    /* ------------------------------------------------------------ catalogue */

    /** @return array<string, Product> name => product (sellable items) */
    private function catalogue(Tenant $tenant, string $kind): array
    {
        $groups = match ($kind) {
            'cafe' => DemoData::cafe(),
            'restaurant' => DemoData::restaurant(),
            default => DemoData::chain(),
        };

        $out = [];
        $order = 0;

        foreach ($groups as $categoryName => $items) {
            $category = Category::create([
                'tenant_id' => $tenant->id, 'name' => $categoryName, 'slug' => Category::uniqueSlug($categoryName),
                'description' => null, 'is_active' => true, 'sort_order' => $order++,
            ]);

            foreach ($items as $i => $item) {
                $out[$item[0]] = $this->makeProduct($tenant, $category, $item, $i);
            }
        }

        return $out;
    }

    private function makeProduct(Tenant $tenant, ?Category $category, array $item, int $sort): Product
    {
        [$name, $price, $food, $gst, $kind] = $item;
        $o = $item[5] ?? [];
        $made = $kind === 'made';
        $variants = $o['variants'] ?? [];
        $startPrice = $variants ? (float) $variants[0][1] : (float) $price;
        $tax = TaxRate::query()->where('rate', $gst)->first() ?? TaxRate::query()->where('is_default', true)->first();
        $unit = Unit::query()->where('code', 'PCS')->first();

        $product = new Product(array_filter([
            'name' => $name, 'slug' => Product::uniqueSlug($name), 'sku' => $this->sku($name),
            'barcode' => (! $made && isset($o['mrp'])) ? '890'.str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT) : null,
            'category_id' => $category?->id, 'unit_id' => $unit?->id, 'tax_rate_id' => $tax?->id,
            'purchase_price' => $o['cost'] ?? 0, 'mrp' => $o['mrp'] ?? $startPrice, 'selling_price' => $startPrice,
            'tax_inclusive' => isset($o['mrp']), 'food_type' => $food,
            'spice_level' => in_array('Spice level', $o['mods'] ?? [], true) ? random_int(1, 3) : null,
            'food_tags' => isset($o['tag']) ? [$o['tag']] : null,
            'serves' => $made ? ($variants && ($variants[count($variants) - 1][0] === 'Full') ? 2 : 1) : null,
            'prep_minutes' => $made ? ($o['prep'] ?? random_int(6, 22)) : null,
            'min_stock' => $made ? 0 : ($o['reorder'] ?? 0) / 2, 'reorder_level' => $made ? 0 : ($o['reorder'] ?? 0),
            'track_batches' => false, 'is_made_to_order' => $made, 'is_ingredient' => false,
            'short_description' => $o['desc'] ?? null, 'is_active' => true, 'is_published' => true,
            'is_featured' => ($o['tag'] ?? null) === 'bestseller', 'sort_order' => $sort,
        ], fn ($v) => $v !== null));
        $product->tenant_id = $tenant->id;
        $product->save();

        foreach ($variants as $v => [$vName, $vPrice]) {
            ProductVariant::create([
                'product_id' => $product->id, 'name' => $vName, 'price' => $vPrice,
                'is_default' => $v === 0, 'is_available' => true, 'sort_order' => $v,
            ]);
        }

        // Stash what the shop step needs, without widening the model.
        $this->meta[$product->id] = $o + ['kind' => $kind];

        return $product;
    }

    /** @return array<string, Product> */
    private function ingredients(Tenant $tenant, string $kind): array
    {
        $rows = match ($kind) {
            'restaurant' => DemoData::restaurantIngredients(),
            'chain' => DemoData::chainIngredients(),
            default => [],
        };

        if ($rows === []) {
            return [];
        }

        $category = Category::create([
            'tenant_id' => $tenant->id, 'name' => 'Raw Materials', 'slug' => Category::uniqueSlug('Raw Materials'),
            'is_active' => true, 'sort_order' => 99,
        ]);

        $out = [];

        foreach ($rows as $i => [$name, $unitCode, $cost, $qty, $reorder, $shelf]) {
            $product = new Product([
                'name' => $name, 'slug' => Product::uniqueSlug($name), 'sku' => $this->sku($name),
                'category_id' => $category->id, 'unit_id' => Unit::query()->where('code', $unitCode)->value('id'),
                'tax_rate_id' => TaxRate::query()->where('rate', 0)->value('id'),
                'purchase_price' => $cost, 'mrp' => 0, 'selling_price' => 0, 'food_type' => null,
                'min_stock' => $reorder / 2, 'reorder_level' => $reorder, 'track_batches' => $shelf !== null,
                'is_made_to_order' => false, 'is_ingredient' => true, 'is_active' => true, 'is_published' => false, 'sort_order' => $i,
            ]);
            $product->tenant_id = $tenant->id;
            $product->save();
            $this->meta[$product->id] = ['cost' => $cost, 'qty' => $qty, 'shelf' => $shelf];
            $out[$name] = $product;
        }

        return $out;
    }

    /* ----------------------------------------------------------------- shop */

    private function seedShop(Tenant $tenant, Shop $shop, array $bp, string $kind, array $products, array $ingredients, string $plan): void
    {
        $this->say("   - {$shop->name}");
        $this->clearContext();

        $warehouse = Warehouse::defaultFor($shop->id);
        $warehouse->forceFill(['name' => 'Main Store', 'address' => $bp['address_line1'], 'city' => $bp['city']])->save();

        $stations = $this->stations($shop, $bp);
        $this->routeStations($tenant, $products, $stations, $kind);

        $this->stockIn($shop, $warehouse, $products, $ingredients, $bp);
        $this->modifiers($shop, $products, $kind);
        // A recipe belongs to the branch that cooks it, so every branch gets its own.
        $this->recipes($shop, $products, $ingredients, $kind);

        $suppliers = $this->suppliers($shop, $kind, $bp);
        $customers = $this->customers($shop, $bp);
        $this->coupons($shop);
        $this->expenses($shop, $suppliers);

        $users = $this->staff($tenant, $shop, $bp, $plan);

        if (! empty($bp['dining'])) {
            $tables = $this->floors($shop, $bp);
        }

        $cashier = $users['Cashier'][0] ?? $users['Manager'][0] ?? null;
        $this->history($shop, $warehouse, $products, $customers, $bp, $cashier);

        if (! empty($bp['dining'])) {
            $this->liveFloor($shop, $products, $tables ?? collect(), $bp, $users);
        }

        $this->say(sprintf('     %d customers, %d suppliers, %d invoices', count($customers), count($suppliers), \App\Models\Invoice::query()->withoutGlobalScopes()->where('shop_id', $shop->id)->count()));
    }

    private function stations(Shop $shop, array $bp): array
    {
        $out = [];

        $defs = $bp['stations'] ?? (! empty($bp['dining']) ? [['Main Kitchen', 'MK', 20, true], ['Beverages', 'BEV', 8, false]] : []);

        foreach ($defs as $i => [$name, $code, $prep, $default]) {
            $out[$name] = KitchenStation::create([
                'shop_id' => $shop->id, 'name' => $name, 'code' => $code, 'prep_minutes' => $prep,
                'is_default' => $default, 'is_active' => true, 'sort_order' => $i,
            ]);
        }

        return $out;
    }

    /** Dishes go to their station through the category; one-off exceptions on the dish. */
    private function routeStations(Tenant $tenant, array $products, array $stations, string $kind): void
    {
        if ($stations === [] || $kind !== 'restaurant') {
            return;
        }

        $byCategory = ['Starters' => 'Main Kitchen', 'Main Course' => 'Main Kitchen', 'Rice & Biryani' => 'Main Kitchen',
            'Breads' => 'Tandoor', 'Desserts' => 'Bar & Desserts', 'Beverages' => 'Bar & Desserts'];

        foreach ($byCategory as $category => $station) {
            Category::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('name', $category)
                ->update(['kitchen_station_id' => $stations[$station]->id]);
        }

        foreach ($products as $product) {
            $want = $this->meta[$product->id]['station'] ?? null;

            if ($want && isset($stations[$want]) && $product->category?->name !== null
                && ($byCategory[$product->category->name] ?? null) !== $want) {
                $product->forceFill(['kitchen_station_id' => $stations[$want]->id])->save();
            }
        }
    }

    private function stockIn(Shop $shop, Warehouse $wh, array $products, array $ingredients, array $bp): void
    {
        $stock = app(StockService::class);

        foreach ($products as $product) {
            $d = $this->meta[$product->id];

            if (($d['kind'] ?? '') !== 'stock') {
                continue;
            }

            // Branches differ a little, so no two shops carry identical numbers.
            $qty = max(2, (int) round($d['qty'] * (0.8 + mt_rand(0, 50) / 100)));
            $stock->receive($product, $qty, $wh, null, (float) $d['cost'], \App\Models\StockMovement::PURCHASE, null, 'Opening stock', $shop->id);
        }

        foreach ($ingredients as $ingredient) {
            $d = $this->meta[$ingredient->id];
            $qty = round($d['qty'] * (0.85 + mt_rand(0, 40) / 100), 2);

            if ($d['shelf'] === null) {
                $stock->receive($ingredient, $qty, $wh, null, (float) $d['cost'], \App\Models\StockMovement::PURCHASE, null, 'Opening stock', $shop->id);

                continue;
            }

            // Perishables arrive in two lots: an older one close to its date and a fresh one.
            foreach ([[0.3, max(1, (int) round($d['shelf'] * 0.35)), 'A'], [0.7, $d['shelf'], 'B']] as [$share, $days, $tag]) {
                $batch = Batch::create([
                    'shop_id' => $shop->id, 'product_id' => $ingredient->id,
                    'batch_no' => 'B'.now()->format('md').$tag.'-'.$ingredient->id,
                    'mfg_date' => now()->subDays(max(1, $d['shelf'] - $days))->toDateString(),
                    'expiry_date' => now()->addDays($days)->toDateString(),
                    'purchase_price' => $d['cost'], 'is_active' => true,
                ]);
                $stock->receive($ingredient, round($qty * $share, 2), $wh, $batch, (float) $d['cost'], \App\Models\StockMovement::PURCHASE, null, 'Opening stock', $shop->id);
            }
        }
    }

    private function modifiers(Shop $shop, array $products, string $kind): void
    {
        foreach (DemoData::modifiers($kind) as $name => [$min, $max, $options]) {
            $modifier = Modifier::create([
                'shop_id' => $shop->id, 'name' => $name, 'instruction' => $min > 0 ? 'Please choose one' : 'Optional extras',
                'min_select' => $min, 'max_select' => $max, 'is_active' => true, 'sort_order' => 0,
            ]);

            foreach ($options as $i => [$optName, $optPrice]) {
                $modifier->options()->create([
                    'name' => $optName, 'price' => $optPrice, 'is_default' => $min > 0 && $i === 0,
                    'is_available' => true, 'sort_order' => $i,
                ]);
            }

            $ids = collect($products)->filter(fn (Product $p) => in_array($name, $this->meta[$p->id]['mods'] ?? [], true))->pluck('id');
            $modifier->products()->syncWithoutDetaching($ids->all());
        }
    }

    private function recipes(Shop $shop, array $products, array $ingredients, string $kind): void
    {
        $book = match ($kind) {
            'restaurant' => DemoData::restaurantRecipes(),
            'chain' => DemoData::chainRecipes(),
            default => [],
        };

        foreach ($book as $dish => $sizes) {
            $product = $products[$dish] ?? null;

            if (! $product) {
                continue;
            }

            foreach ($sizes as $size => $rows) {
                $variantId = $size === '' ? null : ProductVariant::query()->where('product_id', $product->id)->where('name', $size)->value('id');

                foreach ($rows as $i => [$ingredientName, $quantity]) {
                    if (! isset($ingredients[$ingredientName])) {
                        continue;
                    }

                    RecipeItem::create([
                        'shop_id' => $shop->id, 'product_id' => $product->id, 'product_variant_id' => $variantId,
                        'ingredient_id' => $ingredients[$ingredientName]->id, 'quantity' => $quantity, 'sort_order' => $i,
                    ]);
                }
            }
        }
    }

    /* --------------------------------------------------------------- people */

    private function suppliers(Shop $shop, string $kind, array $bp): array
    {
        $out = [];

        foreach (DemoData::suppliers($kind) as $i => [$company, $contact, $city, $state, $days]) {
            $supplier = new Supplier([
                'name' => $company, 'company' => $company, 'contact_person' => $contact,
                'mobile' => $this->mobile(), 'email' => Str::slug($company).'@supplier-demo.example',
                'city' => $city ?? $bp['city'], 'state' => $state ?? $bp['state'], 'state_code' => $bp['state_code'],
                'credit_days' => $days, 'credit_limit' => $days * 20000, 'is_active' => true,
            ]);
            $supplier->shop_id = $shop->id;
            $supplier->code = Supplier::nextCode($shop->id);
            $supplier->save();
            $out[] = $supplier;
        }

        return $out;
    }

    private function customers(Shop $shop, array $bp): array
    {
        $out = [];
        $count = 14;

        for ($i = 0; $i < $count; $i++) {
            $first = DemoData::FIRST[($i * 3 + $shop->id) % count(DemoData::FIRST)];
            $last = DemoData::LAST[($i * 7 + $shop->id) % count(DemoData::LAST)];

            $customer = new Customer([
                'name' => "$first $last", 'mobile' => $this->mobile(), 'email' => Str::lower("$first.$last").($i + 1).'@mail-demo.example',
                'city' => $bp['city'], 'state' => $bp['state'], 'pincode' => $bp['pincode'], 'type' => 'retail',
                'allow_credit' => $i < 4, 'credit_limit' => $i < 4 ? 5000 : 0, 'credit_days' => $i < 4 ? 15 : 0,
                'is_active' => true,
            ]);
            $customer->shop_id = $shop->id;
            $customer->code = Customer::nextCode($shop->id);
            $customer->save();
            $out[] = $customer;
        }

        return $out;
    }

    private function coupons(Shop $shop): void
    {
        $rows = [
            ['WELCOME10', 'percent', 10, 300, 100, 'Ten percent off on your first order'],
            ['FLAT50', 'fixed', 50, 400, null, 'Flat 50 off orders above 400'],
            ['WEEKEND15', 'percent', 15, 600, 150, 'Weekend special: 15% off up to 150'],
        ];

        foreach ($rows as [$code, $type, $value, $min, $cap, $desc]) {
            Coupon::create([
                'shop_id' => $shop->id, 'code' => $code, 'description' => $desc, 'type' => $type, 'value' => $value,
                'min_order_amount' => $min, 'max_discount_amount' => $cap, 'usage_limit' => 500, 'usage_limit_per_customer' => 3,
                'starts_at' => now()->subDays(10), 'expires_at' => now()->addDays(60), 'is_active' => true,
            ]);
        }
    }

    private function expenses(Shop $shop, array $suppliers): void
    {
        $categories = ExpenseCategory::query()->withoutGlobalScopes()
            ->where(fn ($q) => $q->whereNull('shop_id')->orWhere('shop_id', $shop->id))->get()->keyBy('name');
        $pick = fn (string $like) => $categories->first(fn ($c, $name) => stripos($name, $like) !== false)?->id ?? $categories->first()?->id;

        $rows = [
            ['Shop rent', 'rent', 45000, 'bank', 'Landlord', 28], ['Electricity bill', 'electric', 11800, 'upi', 'State Electricity Board', 20],
            ['Staff salaries', 'salar', 86000, 'bank', 'Payroll', 14], ['Gas cylinders (commercial)', 'fuel', 9400, 'cash', 'Bharat Gas Agency', 11],
            ['Cleaning supplies', 'clean', 2650, 'cash', 'Local supplier', 8], ['Equipment repair - grinder', 'repair', 3200, 'upi', 'Service technician', 5],
            ['Internet and phone', 'util', 1799, 'upi', 'Broadband provider', 3], ['Packaging and carry bags', 'pack', 4150, 'cash', 'Packaging wholesaler', 2],
        ];

        foreach ($rows as [$title, $key, $amount, $method, $to, $daysAgo]) {
            $expense = new Expense([
                'expense_category_id' => $pick($key), 'spent_on' => now()->subDays($daysAgo)->toDateString(), 'title' => $title,
                'paid_to' => $to, 'amount' => $amount, 'method' => $method, 'status' => Expense::APPROVED,
            ]);
            $expense->shop_id = $shop->id;
            $expense->reference = Expense::nextReference($shop);
            $expense->approved_by_name = 'Auto-approved (demo)';
            $expense->approved_at = now()->subDays($daysAgo);
            $expense->save();
        }
    }

    private function staff(Tenant $tenant, Shop $shop, array $bp, string $plan): array
    {
        $out = [];
        $domain = Str::slug($tenant->name).'.example';

        foreach ($bp['staff'] as [$name, $role]) {
            $slug = Str::slug(preg_replace('/^Chef |\s[A-Z]$/', '', $name), '.');
            $email = $slug.'@'.$domain;

            $user = User::query()->withoutGlobalScopes()->where('email', $email)->first();

            if (! $user) {
                $user = new User([
                    'tenant_id' => $tenant->id, 'name' => $name, 'email' => $email, 'mobile' => $this->mobile(),
                    'password' => self::PASSWORD, 'is_admin' => true, 'is_active' => true,
                ]);
                $user->email_verified_at = now();
                $user->save();
                $user->assignRole($role);
            }

            $user->shops()->syncWithoutDetaching([$shop->id => ['is_default' => true]]);
            $user->forceFill(['current_shop_id' => $shop->id, 'all_shops_view' => false])->save();

            $out[$role][] = $user;
            $this->accounts[] = ['company' => $tenant->name, 'shop' => $shop->name, 'role' => $role, 'name' => $name, 'email' => $email];
        }

        return $out;
    }

    /* ----------------------------------------------------------- the room */

    private function floors(Shop $shop, array $bp)
    {
        $tables = collect();
        $qr = app(TableQrService::class);

        foreach ($bp['floors'] as $f => [$floorName, $code, $layout]) {
            $floor = Floor::create(['shop_id' => $shop->id, 'name' => $floorName, 'code' => $code, 'is_active' => true, 'sort_order' => $f]);

            foreach ($layout as $n => [$seats, $x, $y]) {
                $number = str_pad((string) ($n + 1), 2, '0', STR_PAD_LEFT);

                $table = RestaurantTable::create([
                    'shop_id' => $shop->id, 'floor_id' => $floor->id, 'name' => (string) ($n + 1), 'code' => "{$code}-{$number}",
                    'capacity' => $seats, 'status' => RestaurantTable::AVAILABLE, 'pos_x' => $x, 'pos_y' => $y,
                    'is_active' => true, 'sort_order' => $n,
                ]);
                $qr->issue($table, 'Initial set');
                $tables->push($table);
            }
        }

        return $tables;
    }

    /* ----------------------------------------------------------- history */

    private function history(Shop $shop, Warehouse $wh, array $products, array $customers, array $bp, ?User $cashier): void
    {
        $sellable = collect($products)->values();
        $invoices = app(InvoiceService::class);
        $methods = ['cash', 'cash', 'upi', 'upi', 'upi', 'card'];

        // Sign in as the cashier so each bill carries a real name, with the branch pinned.
        $this->actAs($cashier, $shop);

        $made = 0;

        for ($n = 0; $n < $bp['sales']; $n++) {
            // Spread over the last three weeks, oldest first, ending today.
            $when = $this->moment($bp, 20 - (int) floor(($n / max(1, $bp['sales'])) * 21));
            $lines = [];

            foreach ($sellable->random(min($sellable->count(), mt_rand(1, 4))) as $product) {
                $variants = ProductVariant::query()->where('product_id', $product->id)->get();
                $variant = $variants->isNotEmpty() ? $variants->random() : null;

                $lines[] = array_filter([
                    'product_id' => $product->id, 'quantity' => mt_rand(1, 3),
                    'unit_price' => $variant?->price, 'name' => $variant ? "{$product->name} ({$variant->name})" : null,
                ], fn ($v) => $v !== null);
            }

            $customer = mt_rand(1, 100) <= 30 ? $customers[array_rand($customers)] : null;
            $credit = $customer && $customer->allow_credit && mt_rand(1, 100) <= 35;

            try {
                $shop->refresh();
                $data = ['shop' => $shop, 'warehouse' => $wh, 'customer' => $customer, 'items' => $lines, 'invoiced_at' => $when, 'channel' => 'pos'];

                if ($credit) {
                    $data += ['is_credit' => true, 'due_date' => $when->copy()->addDays(15)->toDateString(), 'payments' => []];
                    $invoices->create($data);
                } else {
                    // Paid in full. The total is worked out here (GST and rounding to the
                    // rupee); a paisa of disagreement with the service shows up as one rupee
                    // either way, so try the neighbours before giving the bill up.
                    $estimate = $this->estimate($lines, $sellable->keyBy('id'));
                    $last = null;

                    foreach ([$estimate, $estimate + 1, $estimate - 1] as $candidate) {
                        try {
                            $shop->refresh();
                            $invoices->create(array_merge($data, ['shop' => $shop, 'payments' => $this->tenders($candidate, $methods)]));
                            $last = null;
                            break;
                        } catch (Throwable $e) {
                            $last = $e;
                        }
                    }

                    if ($last) {
                        throw $last;
                    }
                }

                $made++;
            } catch (Throwable $e) {
                // Out of stock on a tracked item, or a rule the demo does not need: skip this bill.
                $this->skipped[$e->getMessage()] = ($this->skipped[$e->getMessage()] ?? 0) + 1;

                continue;
            }
        }

        $this->actAs(null, $shop);
    }

    /** @param \Illuminate\Support\Collection<int, Product> $byId */
    private function estimate(array $lines, $byId): float
    {
        $sum = 0.0;

        foreach ($lines as $line) {
            $product = $byId[$line['product_id']];
            $price = (float) ($line['unit_price'] ?? $product->selling_price);
            $rate = (float) ($product->taxRate?->rate ?? 0);
            $amount = $price * (int) $line['quantity'];
            $sum += $product->tax_inclusive ? round($amount, 2) : round($amount * (1 + $rate / 100), 2);
        }

        return (float) round($sum);
    }

    private function tenders(float $total, array $methods): array
    {
        if ($total <= 0) {
            return [];
        }

        if ($total > 400 && mt_rand(1, 100) <= 20) {
            $first = round($total * 0.4, 2);

            return [['method' => 'cash', 'amount' => $first], ['method' => 'upi', 'amount' => round($total - $first, 2)]];
        }

        return [['method' => $methods[array_rand($methods)], 'amount' => $total]];
    }

    private function moment(array $bp, int $daysAgo): Carbon
    {
        $hour = mt_rand($bp['opens'], $bp['closes'] - 1);
        // Lunch and dinner are busier than the afternoon.
        if (mt_rand(1, 100) <= 55) {
            $hour = [13, 14, 20, 21][mt_rand(0, 3)];
            $hour = min($hour, $bp['closes'] - 1);
        }

        $moment = now()->subDays($daysAgo)->setTime($hour, mt_rand(0, 59), mt_rand(0, 59));

        return $moment->isFuture() ? now()->subMinutes(mt_rand(20, 180)) : $moment;
    }

    /* ----------------------------------------------------- live dining floor */

    private function liveFloor(Shop $shop, array $products, $tables, array $bp, array $users): void
    {
        if ($tables->isEmpty()) {
            return;
        }

        $captain = ($users['Captain / Waiter'][0] ?? null) ?? ($users['Manager'][0] ?? null);
        $this->actAs($captain, $shop);

        // Bookings for tonight and the next few days; a couple are confirmed.
        if (! empty($bp['reservations'])) {
            $guests = [['Mehta Family', 4, 3], ['Anita Deshpande', 2, 5], ['Rajiv & friends', 6, 26], ['Corporate lunch - Infosys', 10, 28], ['Birthday - Kavya', 8, 50]];

            foreach (array_slice($guests, 0, $bp['reservations']) as $i => [$guest, $party, $hours]) {
                $table = $tables->first(fn ($t) => $t->capacity >= $party);

                try {
                    $reservation = app(ReservationService::class)->create([
                        'shop_id' => $shop->id, 'restaurant_table_id' => $i < 2 ? $table?->id : null, 'guest_name' => $guest,
                        'guest_mobile' => $this->mobile(), 'party_size' => $party, 'reserved_for' => now()->addHours($hours),
                        'duration_minutes' => 90, 'source' => 'phone', 'notes' => $i === 4 ? 'Needs a cake table and balloons' : null,
                    ]);
                    $reservation->forceFill(['status' => $i % 2 === 0 ? Reservation::CONFIRMED : Reservation::REQUESTED])->save();
                } catch (Throwable $e) {
                    continue;
                }
            }
        }

        // A few parties already eating, with orders in the kitchen.
        $dishes = collect($products)->filter(fn (Product $p) => $p->is_made_to_order)->values();
        $seated = 0;

        foreach ($tables->sortByDesc('capacity')->take(($bp['live_tables'] ?? 0)) as $table) {
            try {
                $session = app(TableSessionService::class)->openFor($table);
                $cart = app(TableCartService::class);

                foreach ($dishes->random(min(3, $dishes->count())) as $dish) {
                    $variant = ProductVariant::query()->where('product_id', $dish->id)->where('is_default', true)->value('id');
                    $mods = $dish->modifiers()->with('options')->get();
                    $options = $mods->map(fn ($m) => $m->min_select > 0 ? $m->options->first()?->id : null)->filter()->values()->all();
                    $cart->add($session, $dish, $variant, mt_rand(1, 2), $options);
                }

                app(TableOrderService::class)->place($session, ['name' => DemoData::FIRST[array_rand(DemoData::FIRST)], 'mobile' => $this->mobile()]);
                $session->forceFill(['opened_at' => now()->subMinutes(mt_rand(18, 55)), 'last_activity_at' => now()->subMinutes(mt_rand(2, 10))])->save();
                $seated++;
            } catch (Throwable $e) {
                $this->say('     (could not seat '.$table->code.': '.$e->getMessage().')');
            }
        }

        // One table being wiped down, one held for a booking.
        $free = $tables->filter(fn ($t) => $t->fresh()->status === RestaurantTable::AVAILABLE)->values();

        if ($free->count() >= 2) {
            $free[0]->forceFill(['status' => RestaurantTable::CLEANING])->save();
            $free[1]->forceFill(['status' => RestaurantTable::RESERVED, 'note' => 'Held for 8:30 pm booking'])->save();
        }

        $this->actAs(null, $shop);
        $this->say("     {$seated} tables seated, ".$tables->count().' tables on the plan');
    }

    /* --------------------------------------------------------------- helpers */

    private function actAs(?User $user, Shop $shop): void
    {
        $this->clearContext();

        if ($user) {
            $user->forceFill(['current_shop_id' => $shop->id, 'all_shops_view' => false])->save();
            Auth::setUser($user);
            CurrentShop::forget();
        }
    }

    private function clearContext(): void
    {
        Auth::forgetGuards();
        CurrentShop::forget();
        CurrentTenant::forget();
    }

    private function uniqueCode(string $table, string $base, ?int $ignoreId): string
    {
        $code = Str::upper(Str::limit(Str::slug($base, ''), 10, ''));
        $try = $code;
        $i = 2;

        while (DB::table($table)->where('code', $try)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $try = $code.$i++;
        }

        return $try;
    }

    private function sku(string $name): string
    {
        return Product::generateSku($name);
    }

    private array $usedMobiles = [];

    private function mobile(): string
    {
        do {
            $number = ['98', '97', '99', '90', '88', '70'][mt_rand(0, 5)].str_pad((string) mt_rand(0, 99999999), 8, '0', STR_PAD_LEFT);
        } while (isset($this->usedMobiles[$number]));

        return $this->usedMobiles[$number] = $number;
    }

    private function say(string $line): void
    {
        if ($this->command ?? null) {
            $this->command->info($line);
        }
    }
}
