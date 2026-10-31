<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUniqueSlug;
use App\Support\CurrentShop;
use App\Support\PriceLists;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The product catalogue - one per restaurant.
 *
 * Owned by a tenant (BelongsToTenant) and shared by that tenant's branches;
 * it was global once, which leaked one company's menu to every other - see
 * the add_tenant_to_catalogue migration. Anything that varies per branch is
 * reached through the shop-scoped relations below:
 *
 *   sellingPriceFor()     product_shop override, falling back to the master
 *   channelPriceFor()     the same, for one order channel (§8)
 *   stockOnHand()         product_stocks in the current shop
 *   batches               lots held by the current shop
 *
 * Every one of those defaults to the shop the request is working in, so an
 * ordinary caller never has to remember to pass one.
 */
class Product extends Model
{
    use BelongsToTenant, HasUniqueSlug, SoftDeletes;

    /**
     * This product's override row per shop, read from the database once.
     *
     * Every price accessor asks for it (selling, purchase, MRP, counter...),
     * so without this a single card on a list page cost one query per field.
     * Cleared by forgetOverrides() and refresh().
     *
     * @var array<int, Shop|null>
     */
    private array $overrideCache = [];

    /**
     * Stock totals handed in for a whole page by preloadStock(), keyed by
     * shop ("all" for no single shop). Never filled implicitly: a read made
     * after a sale must see the sale.
     *
     * @var array<string, array{on_hand: float, reserved: float}>
     */
    private array $stockSnapshot = [];

    /* ------------------------------------------------------------- menu */

    /**
     * What kind of food a row is (§8).
     *
     * Null is not "unknown veg" - it is a row that is not food: a bottle of
     * water, a paper bag, a service-charge line. Those must not get a mark,
     * and defaulting them to veg would put one on them.
     *
     * The dot colours are the Indian convention and are not decoration: a
     * green square means vegetarian and a brown one does not, and getting
     * that wrong is a complaint rather than a design note.
     *
     * @var array<string, array{label: string, dot: string, short: string}>
     */
    public const FOOD_TYPES = [
        'veg' => ['label' => 'Vegetarian', 'dot' => '#16a34a', 'short' => 'Veg'],
        'egg' => ['label' => 'Contains egg', 'dot' => '#d97706', 'short' => 'Egg'],
        'non_veg' => ['label' => 'Non-vegetarian', 'dot' => '#b91c1c', 'short' => 'Non-veg'],
        'vegan' => ['label' => 'Vegan', 'dot' => '#15803d', 'short' => 'Vegan'],
        'jain' => ['label' => 'Jain', 'dot' => '#16a34a', 'short' => 'Jain'],
    ];

    /** @var array<int, string> */
    public const SPICE_LEVELS = [
        0 => 'Not spicy',
        1 => 'Mild',
        2 => 'Medium',
        3 => 'Hot',
    ];

    /**
     * The channels a dish can be priced differently for (§8).
     *
     * `dine_in` is the base case and falls back to `selling_price`, so a
     * restaurant that charges the same everywhere fills in nothing.
     *
     * @var array<string, string>
     */
    public const CHANNELS = [
        'dine_in' => 'Dine-in',
        'takeaway' => 'Takeaway',
        'delivery' => 'Delivery',
    ];

    protected $fillable = [
        'name', 'slug', 'sku', 'barcode',
        'category_id', 'brand_id', 'unit_id', 'tax_rate_id', 'kitchen_station_id',
        'hsn_code', 'manufacturer',
        'purchase_price', 'mrp', 'selling_price', 'discount_percent', 'tax_inclusive',
        'dine_in_price', 'takeaway_price', 'delivery_price',
        'food_type', 'spice_level', 'food_tags', 'serves', 'prep_minutes',
        'is_sold_out', 'sold_out_until',
        'available_from', 'available_to', 'available_days',
        'min_stock', 'reorder_level', 'track_batches', 'is_made_to_order', 'is_ingredient',
        'short_description', 'description',
        'is_active', 'is_published', 'is_featured',
        'meta_title', 'meta_description', 'meta_keywords',
        'sort_order',
    ];

    // image_path is set only by the controller's upload handler.

    protected function casts(): array
    {
        return [
            'purchase_price' => 'decimal:4',
            'mrp' => 'decimal:4',
            'selling_price' => 'decimal:4',
            'discount_percent' => 'decimal:3',
            'min_stock' => 'decimal:3',
            'reorder_level' => 'decimal:3',

            'dine_in_price' => 'decimal:4',
            'takeaway_price' => 'decimal:4',
            'delivery_price' => 'decimal:4',
            'spice_level' => 'integer',
            'food_tags' => 'array',
            'serves' => 'integer',
            'prep_minutes' => 'integer',
            'is_sold_out' => 'boolean',
            'sold_out_until' => 'datetime',
            'available_days' => 'array',

            'tax_inclusive' => 'boolean',
            'track_batches' => 'boolean',
            'is_made_to_order' => 'boolean',
            'is_ingredient' => 'boolean',
            'is_active' => 'boolean',
            'is_published' => 'boolean',
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /* --------------------------------------------------------- relations */

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Where this dish is cooked, overriding its category (§9).
     *
     * Null is the normal case and means "wherever the section goes" - see
     * KitchenRouter. Set on the few dishes that do not follow their section:
     * the one dessert that comes off the tandoor, the mocktail filed under
     * Starters.
     */
    public function kitchenStation(): BelongsTo
    {
        return $this->belongsTo(KitchenStation::class, 'kitchen_station_id');
    }

    /** What this dish is made of (§10). */
    public function recipeItems(): HasMany
    {
        return $this->hasMany(RecipeItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /** The recipes this product appears in as a component. */
    public function usedInRecipes(): HasMany
    {
        return $this->hasMany(RecipeItem::class, 'ingredient_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    /** Sizes: Half / Full, Small / Medium / Large. */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /** The questions asked about this dish, in this dish's own order. */
    public function modifiers(): BelongsToMany
    {
        return $this->belongsToMany(Modifier::class)
            ->withPivot('sort_order')
            ->orderBy('modifier_product.sort_order');
    }

    /** Per-shop price and reorder overrides. */
    public function shops(): BelongsToMany
    {
        return $this->belongsToMany(Shop::class)
            ->withPivot([
                'purchase_price', 'mrp', 'selling_price', 'discount_percent',
                'min_stock', 'reorder_level', 'is_active',
            ])
            ->withTimestamps();
    }

    /** Lots of this product, in whichever shops the reader may see. */
    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(ProductStock::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /* ------------------------------------------------------------ scopes */

    /**
     * Things a guest could be sold.
     *
     * Ingredients are active, stocked and purchased like any other product -
     * they are simply not on the menu. Every screen that sells reads this
     * rather than `active()` alone, so flour cannot end up on a card.
     */
    public function scopeSellable(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_ingredient', false);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where('is_published', true)
            // An ingredient is never a storefront row, whatever else is
            // ticked on it. Belt and braces: is_published defaults off, but
            // one careless bulk edit should not put flour in a shop window.
            ->where('is_ingredient', false);
    }

    /**
     * Products a given shop actually sells.
     *
     * product_shop is opt-out, not opt-in - see its migration. A shop that
     * has never customised a product has no row there at all, and that
     * means "available with the master values", not "unavailable". So this
     * excludes only the shops that explicitly switched a product off,
     * rather than requiring an active override row to exist.
     */
    public function scopeAvailableAt(Builder $query, int $shopId): Builder
    {
        return $query->whereDoesntHave('shops', fn (Builder $q) => $q
            ->where('shops.id', $shopId)
            ->where('product_shop.is_active', false));
    }

    /**
     * Free-text across everything the counter might type.
     *
     * SKU and barcode are matched exactly as well as partially, so scanning
     * a barcode that happens to be a substring of another cannot put the
     * wrong product first.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('name', 'like', "%{$term}%")
            ->orWhere('sku', 'like', "%{$term}%")
            ->orWhere('barcode', 'like', "%{$term}%")
            ->orWhere('hsn_code', 'like', "%{$term}%")
            ->orWhere('manufacturer', 'like', "%{$term}%"));
    }

    /** Exactly one product, by the thing a scanner sends. */
    public function scopeByBarcode(Builder $query, string $code): Builder
    {
        return $query->where('barcode', $code)->orWhere('sku', $code);
    }

    /* --------------------------------------------- shop-specific pricing */

    /**
     * This shop's override row, if it has one.
     */
    public function overrideFor(?int $shopId = null): ?Shop
    {
        $shopId ??= CurrentShop::id();

        if ($shopId === null) {
            return null;
        }

        if ($this->relationLoaded('shops')) {
            return $this->shops->firstWhere('id', $shopId);
        }

        if (! array_key_exists($shopId, $this->overrideCache)) {
            $this->overrideCache[$shopId] = $this->shops()->wherePivot('shop_id', $shopId)->first();
        }

        return $this->overrideCache[$shopId];
    }

    /**
     * Drop what was remembered about this product's overrides and stock.
     *
     * Call after writing the product_shop pivot, so the next price read on
     * this same instance sees the change.
     */
    public function forgetOverrides(): void
    {
        $this->overrideCache = [];
        $this->stockSnapshot = [];
        $this->unsetRelation('shops');
    }

    public function refresh()
    {
        $this->overrideCache = [];
        $this->stockSnapshot = [];

        return parent::refresh();
    }

    /**
     * Read one priced field for a shop, falling back to the master value.
     *
     * A null override means "inherit", which is what lets a central price
     * change still reach every branch that has not opted out.
     */
    private function pricedField(string $field, ?int $shopId): float
    {
        $override = $this->overrideFor($shopId)?->pivot?->{$field};

        return (float) ($override ?? $this->{$field});
    }

    public function sellingPriceFor(?int $shopId = null): float
    {
        return $this->pricedField('selling_price', $shopId);
    }

    public function purchasePriceFor(?int $shopId = null): float
    {
        return $this->pricedField('purchase_price', $shopId);
    }

    /**
     * What the customer actually hands over, per unit, tax included.
     *
     * The counter thinks in one number: a cashier typing 500 means five
     * hundred rupees. A tax-exclusive shelf price has to be grossed up to
     * reach it. That happens here rather than in each caller, because the
     * lookup that fills the till and the check that decides whether a
     * typed price is a discount must agree to the paisa.
     */
    public function counterPriceFor(?int $shopId = null): float
    {
        $price = $this->sellingPriceFor($shopId);
        $rate = (float) ($this->taxRate?->rate ?? 0);

        return $this->tax_inclusive || $rate <= 0
            ? round($price, 2)
            : round($price * (1 + $rate / 100), 2);
    }

    public function mrpFor(?int $shopId = null): float
    {
        return $this->pricedField('mrp', $shopId);
    }

    public function reorderLevelFor(?int $shopId = null): float
    {
        return $this->pricedField('reorder_level', $shopId);
    }

    public function minStockFor(?int $shopId = null): float
    {
        return $this->pricedField('min_stock', $shopId);
    }

    /* ------------------------------------------------------------- stock */

    /**
     * Whether a count of this row means anything at all.
     *
     * A dish is made to order: there is no shelf of Butter Naan to count, no
     * movement when one is sold, and a number against it is therefore a
     * number that can only ever be wrong. What a kitchen does have a count of
     * is flour and butter, and those come off the shelf through the recipe
     * when the ticket is cooked - see RecipeService.
     *
     * Every screen that shows, values, caps or alerts on stock asks this
     * first, so none of them can present a figure the sale will not move.
     */
    public function tracksStock(): bool
    {
        return ! (bool) $this->is_made_to_order;
    }

    /**
     * Quantity on hand, summed across warehouses and batches.
     *
     * Defaults to the shop in context; in All-shops mode it is the total
     * across every shop the reader may see, which is the only reading of
     * "how much do we have" that makes sense from a consolidated view.
     */
    public function stockOnHand(?int $shopId = null): float
    {
        $shopId ??= CurrentShop::id();

        if (isset($this->stockSnapshot[$shopId ?? 'all'])) {
            return $this->stockSnapshot[$shopId ?? 'all']['on_hand'];
        }

        $query = ProductStock::query()->where('product_id', $this->id);

        if ($shopId !== null) {
            $query->where('shop_id', $shopId);
        }

        return (float) $query->sum('quantity');
    }

    /** On hand minus what is held for unpicked online orders. */
    public function availableStock(?int $shopId = null): float
    {
        $shopId ??= CurrentShop::id();

        if (isset($this->stockSnapshot[$shopId ?? 'all'])) {
            $held = $this->stockSnapshot[$shopId ?? 'all'];

            return $held['on_hand'] - $held['reserved'];
        }

        $query = ProductStock::query()->where('product_id', $this->id);

        if ($shopId !== null) {
            $query->where('shop_id', $shopId);
        }

        // Both totals in one round trip; this used to be two aggregate queries.
        $totals = $query
            ->selectRaw('COALESCE(SUM(quantity), 0) as on_hand')
            ->selectRaw('COALESCE(SUM(reserved), 0) as reserved')
            ->toBase()
            ->first();

        return (float) ($totals->on_hand ?? 0) - (float) ($totals->reserved ?? 0);
    }

    /**
     * Read the stock of a whole page of products in one grouped query.
     *
     * For list and catalogue pages, where asking each product for its own
     * stock is a query per card. The totals are exactly what stockOnHand() and
     * availableStock() would have returned for the same shop, and are held on
     * each product until it is refreshed.
     *
     * @param  iterable<int, Product>  $products
     */
    public static function preloadStock(iterable $products, ?int $shopId): void
    {
        $products = collect($products)->values();

        if ($products->isEmpty()) {
            return;
        }

        $rows = ProductStock::query()
            ->whereIn('product_id', $products->pluck('id')->all())
            ->when($shopId !== null, fn (Builder $q) => $q->where('shop_id', $shopId))
            ->selectRaw('product_id')
            ->selectRaw('COALESCE(SUM(quantity), 0) as on_hand')
            ->selectRaw('COALESCE(SUM(reserved), 0) as reserved')
            ->groupBy('product_id')
            ->toBase()
            ->get()
            ->keyBy('product_id');

        foreach ($products as $product) {
            $row = $rows->get($product->id);

            $product->stockSnapshot[$shopId ?? 'all'] = [
                'on_hand' => (float) ($row->on_hand ?? 0),
                'reserved' => (float) ($row->reserved ?? 0),
            ];
        }
    }

    public function isLowStock(?int $shopId = null): bool
    {
        // A dish has no shelf to run low - see tracksStock().
        if (! $this->tracksStock()) {
            return false;
        }

        $level = $this->reorderLevelFor($shopId);

        return $level > 0 && $this->stockOnHand($shopId) <= $level;
    }

    /* -------------------------------------------------------------- menu */

    /**
     * The price for one channel, falling back to the shelf price.
     *
     * Null on a channel column means "the same as everywhere else", never
     * "free". A restaurant that charges one price fills in nothing, and a
     * zero typed into `delivery_price` is an explicit decision to give it
     * away - which is why `??` and not `?:`.
     */
    public function channelPriceFor(string $channel, ?int $shopId = null): float
    {
        $column = match ($channel) {
            'takeaway' => $this->takeaway_price,
            'delivery' => $this->delivery_price,
            'dine_in' => $this->dine_in_price,
            default => null,
        };

        $normal = $column === null
            ? $this->sellingPriceFor($shopId)
            : (float) $column;

        /*
         | A price list, if one is running (§8, §16).
         |
         | Applied here rather than at each call site, so a happy hour reaches
         | the counter, the QR menu, the API and every order placed through
         | any of them - which is what a happy hour means.
         |
         | Null when no list applies, which is the normal case and the whole
         | safety of the feature: an install with no price lists gets exactly
         | the figure it got before this existed. See App\Support\PriceLists.
         */
        return PriceLists::priceFor(
            productId: $this->id,
            variantId: null,
            normal: $normal,
            shopId: $shopId,
            channel: $channel,
        ) ?? $normal;
    }

    public function foodTypeLabel(): ?string
    {
        return self::FOOD_TYPES[$this->food_type]['label'] ?? null;
    }

    /** The colour of the square printed next to the name. */
    public function foodTypeDot(): ?string
    {
        return self::FOOD_TYPES[$this->food_type]['dot'] ?? null;
    }

    public function spiceLabel(): ?string
    {
        $level = (int) $this->spice_level;

        return $level > 0 ? (self::SPICE_LEVELS[$level] ?? null) : null;
    }

    /**
     * Whether it is being served right now.
     *
     * Three questions, and all three have to say yes:
     *
     *   1. is the row switched on at all
     *   2. has the kitchen marked it sold out
     *   3. is it inside its serving window today
     *
     * Kept as one method because every caller wants the same answer and a
     * customer menu that disagreed with the POS about whether breakfast is
     * on would be worse than either being wrong alone.
     */
    public function isOrderable(?\DateTimeInterface $at = null): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return ! $this->isSoldOut($at) && $this->isInServingWindow($at);
    }

    /**
     * Sold out, with the overnight reprieve §8 implies.
     *
     * `sold_out_until` in the past clears itself rather than needing somebody
     * to remember at midnight - a kitchen that runs out of prawns at nine
     * wants them back tomorrow, not a note on the fridge.
     *
     * The flag is not written back here. Reading is not the place to write,
     * and a menu page for a guest must not fire an UPDATE.
     */
    public function isSoldOut(?\DateTimeInterface $at = null): bool
    {
        if (! $this->is_sold_out) {
            return false;
        }

        if ($this->sold_out_until === null) {
            return true;
        }

        return $this->sold_out_until->isAfter($at ?? now());
    }

    /**
     * Whether the clock and the calendar allow it (§8).
     *
     * A window that wraps midnight is honoured - a late-night menu running
     * 23:00 to 02:00 is a real thing, and comparing "now" to a plain
     * from <= now <= to would serve it for one hour a night.
     */
    public function isInServingWindow(?\DateTimeInterface $at = null): bool
    {
        $now = $at ? \Illuminate\Support\Carbon::instance($at) : now();

        $days = $this->available_days;

        if (is_array($days) && $days !== [] && ! in_array((int) $now->dayOfWeekIso, array_map('intval', $days), true)) {
            return false;
        }

        $from = $this->available_from;
        $to = $this->available_to;

        // Both open, or only one set, means no window worth enforcing.
        if (blank($from) || blank($to)) {
            return true;
        }

        $clock = $now->format('H:i:s');
        $from = substr((string) $from, 0, 8);
        $to = substr((string) $to, 0, 8);

        return $from <= $to
            ? ($clock >= $from && $clock <= $to)
            : ($clock >= $from || $clock <= $to);   // wraps midnight
    }

    /**
     * Why the online store cannot take it right now, or null when it can.
     *
     * Two kinds of product answer this differently, and the storefront used to
     * ask only the first question of both: a shelf item is out when none is
     * available; a dish is made to order, has no shelf, and is out when the
     * kitchen has switched it off or it is outside its serving window - the
     * same answer the till gives (see isOrderable()). Asking a dish how many
     * are on a shelf returned zero and marked the whole menu "Out of stock",
     * with the Add button disabled, while the order service would have
     * accepted every one of them.
     */
    public function storefrontBlock(?int $shopId = null): ?string
    {
        if ($this->tracksStock()) {
            return $this->availableStock($shopId) <= 0 ? 'Out of stock' : null;
        }

        return $this->unavailableReason();
    }

    /** Why it cannot be ordered, for a menu card that has to say something. */
    public function unavailableReason(?\DateTimeInterface $at = null): ?string
    {
        if (! $this->is_active) {
            return 'Not on the menu';
        }

        if ($this->isSoldOut($at)) {
            return 'Sold out';
        }

        if (! $this->isInServingWindow($at)) {
            return $this->servingWindowLabel() ?? 'Not being served now';
        }

        return null;
    }

    /** "Served 07:00 – 11:00", for the card. */
    public function servingWindowLabel(): ?string
    {
        if (blank($this->available_from) || blank($this->available_to)) {
            return null;
        }

        $short = fn (string $time) => \Illuminate\Support\Carbon::createFromFormat(
            'H:i:s', substr($time, 0, 8)
        )->format('g:i a');

        return 'Served '.$short((string) $this->available_from).' – '.$short((string) $this->available_to);
    }

    public function hasVariants(): bool
    {
        return $this->variants()->exists();
    }

    /* --------------------------------------------------------- accessors */

    public function imageUrl(): ?string
    {
        if (blank($this->image_path)) {
            return null;
        }

        $disk = Storage::disk('public');

        return $disk->exists($this->image_path) ? $disk->url($this->image_path) : null;
    }

    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->filter()
            ->take(2)
            ->map(fn (string $part) => Str::substr($part, 0, 1))
            ->implode('');
    }

    /**
     * What a label or a picker should show alongside the name.
     */
    public function reference(): string
    {
        return $this->barcode ?: $this->sku;
    }

    /**
     * The shape the counter's line editor expects.
     *
     * Lives on the model rather than in the controller that first needed it,
     * because two screens now build rows from it - the product lookup and a
     * resumed held sale - and a serialiser copied into both would drift the
     * first time a column was added.
     *
     * The counter thinks in what the customer pays, so a tax-exclusive price
     * is grossed up here; PosController tells the invoice service that these
     * prices include tax, which closes the loop.
     *
     * @param  array{on_hand: float, available: float}|null  $stock
     *   Already-counted stock for this product, when the caller has read it
     *   in one grouped query. The counter's menu grid renders every dish at
     *   once, and asking the stock table three times per tile would be a
     *   few hundred queries for one screen. Left null - the search box, a
     *   resumed hold - each row counts its own, exactly as before.
     * @return array<string, mixed>
     */
    public function toLookupArray(?int $shopId, ?array $stock = null): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'unit' => $this->unit?->code,
            'allow_decimal' => (bool) $this->unit?->allow_decimal,
            'selling_price' => $this->counterPriceFor($shopId),
            // Purchase documents prefill their cost column from this. Without
            // it the line editor falls back to the selling price and every
            // receipt arrives valued at retail.
            'average_cost' => $this->purchasePriceFor($shopId),
            'mrp' => $this->mrpFor($shopId),
            'tax_rate_id' => $this->tax_rate_id,
            'tax_inclusive' => $this->tax_inclusive,
            'track_batches' => $this->track_batches,
            'is_made_to_order' => $this->is_made_to_order,
            'on_hand' => $stock['on_hand'] ?? $this->stockOnHand($shopId),
            'available' => $stock['available'] ?? $this->availableStock($shopId),
            'image' => $this->imageUrl(),
        ];
    }

    protected static function slugFallback(): string
    {
        return 'product';
    }

    /**
     * Mint an SKU that is not already taken.
     *
     * Derived from the name so it stays human, with a numeric tail for
     * uniqueness. Kept short enough to fit a shelf label.
     */
    public static function generateSku(string $name): string
    {
        $base = Str::of($name)->ascii()->upper()->replaceMatches('/[^A-Z0-9]+/', '')->substr(0, 6);
        $base = $base->isEmpty() ? 'PRD' : (string) $base;

        do {
            $sku = $base.'-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT);
        } while (static::withTrashed()->where('sku', $sku)->exists());

        return $sku;
    }
}
