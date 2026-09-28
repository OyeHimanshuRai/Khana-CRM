<?php

namespace App\Events\Outbound;

use App\Models\Category;
use App\Models\Modifier;
use App\Models\ModifierOption;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use WeakMap;

/**
 * Notices every write that changes what crm.menu.item_upserted says, on the
 * models themselves - so the product form, the CSV import, the add-on
 * screen, a seeder and anything added later are all covered without each
 * remembering to announce.
 *
 * Only the columns the contract carries count. Sold-out, the published
 * flag, a photo, the cost ERP itself sent - none of them are in the event,
 * and reacting to them would echo ERP's own updates straight back to it.
 *
 * Two changes happen off the models, on pivot tables, and are announced by
 * the code that makes them: an outlet's own price or listing
 * (ProductController::syncOverride) and which dishes ask an add-on question
 * (ModifierController::syncProducts).
 */
final class MenuWatch
{
    /** Product columns crm.menu.item_upserted reads. */
    private const PRODUCT = ['name', 'category_id', 'food_type', 'is_active', 'selling_price', 'sku', 'barcode', 'tenant_id'];

    private const VARIANT = ['product_id', 'name', 'sku', 'price', 'is_default'];

    private const MODIFIER = ['name', 'is_active', 'shop_id'];

    private const OPTION = ['modifier_id', 'name', 'price', 'is_available'];

    /**
     * Dishes a category or a question was attached to, noted before the row
     * goes - its links are gone by the time "deleted" fires.
     *
     * @var WeakMap<Model, array{0: list<int>, 1: int|null}>|null
     */
    private static ?WeakMap $leaving = null;

    public static function register(): void
    {
        self::$leaving = new WeakMap();

        /* ------------------------------------------------------ the dish */

        Product::created(fn (Product $product) => self::changes()->changed($product));
        Product::updated(function (Product $product) {
            if ($product->wasChanged(self::PRODUCT)) {
                self::changes()->changed($product);
            }
        });
        Product::softDeleted(fn (Product $product) => self::changes()->archived($product));
        Product::restored(fn (Product $product) => self::changes()->changed($product));

        /* --------------------------------------------------------- sizes */

        ProductVariant::created(fn (ProductVariant $variant) => self::changes()->changed((int) $variant->product_id));
        ProductVariant::updated(function (ProductVariant $variant) {
            if ($variant->wasChanged(self::VARIANT)) {
                self::changes()->changed(array_filter([(int) $variant->product_id, (int) $variant->getOriginal('product_id')]));
            }
        });
        ProductVariant::deleted(fn (ProductVariant $variant) => self::changes()->changed((int) $variant->product_id));

        /* --------------------------------------------------- categories */

        Category::updated(function (Category $category) {
            if ($category->wasChanged('name')) {
                self::changes()->changed(self::dishesIn($category));
            }
        });
        Category::deleting(fn (Category $category) => self::note($category, self::dishesIn($category), null));
        Category::deleted(fn (Category $category) => self::release($category));

        /* ---------------------------------------- add-on questions (per outlet) */

        Modifier::updated(function (Modifier $modifier) {
            if ($modifier->wasChanged(self::MODIFIER)) {
                // Its own outlet's menu - unless it moved outlet, which
                // touches two, so every outlet is the honest answer.
                self::changes()->changed(
                    self::dishesAsking((int) $modifier->id),
                    $modifier->wasChanged('shop_id') ? null : (int) $modifier->shop_id,
                );
            }
        });
        Modifier::deleting(fn (Modifier $modifier) => self::note($modifier, self::dishesAsking((int) $modifier->id), (int) $modifier->shop_id));
        Modifier::deleted(fn (Modifier $modifier) => self::release($modifier));

        ModifierOption::created(fn (ModifierOption $option) => self::optionChanged($option));
        ModifierOption::updated(function (ModifierOption $option) {
            if ($option->wasChanged(self::OPTION)) {
                self::optionChanged($option);
            }
        });
        ModifierOption::deleted(fn (ModifierOption $option) => self::optionChanged($option));
    }

    private static function changes(): MenuChanges
    {
        return app(MenuChanges::class);
    }

    private static function optionChanged(ModifierOption $option): void
    {
        $shopId = Modifier::query()->withoutGlobalScopes()->whereKey($option->modifier_id)->value('shop_id');

        self::changes()->changed(self::dishesAsking((int) $option->modifier_id), $shopId === null ? null : (int) $shopId);
    }

    /** @return list<int> */
    private static function dishesIn(Category $category): array
    {
        return Product::allTenants()->where('category_id', $category->id)->pluck('id')->all();
    }

    /** @return list<int> */
    private static function dishesAsking(int $modifierId): array
    {
        return DB::table('modifier_product')->where('modifier_id', $modifierId)->pluck('product_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @param  list<int>  $products
     */
    private static function note(Model $row, array $products, ?int $shopId): void
    {
        self::$leaving ??= new WeakMap();
        self::$leaving[$row] = [$products, $shopId];
    }

    private static function release(Model $row): void
    {
        if (self::$leaving === null || ! isset(self::$leaving[$row])) {
            return;
        }

        [$products, $shopId] = self::$leaving[$row];
        unset(self::$leaving[$row]);

        self::changes()->changed($products, $shopId);
    }
}
