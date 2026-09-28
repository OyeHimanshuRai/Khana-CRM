<?php

namespace App\Events\Outbound;

use App\Models\Category;
use App\Models\Modifier;
use App\Models\ModifierOption;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use Illuminate\Support\Facades\DB;

/**
 * One menu item as one outlet sells it: the `data` of crm.menu.item_upserted,
 * and each row of GET /api/v1/outlets/{id}/menu.
 *
 * Per outlet because that is how CRM's menu is shaped. The dish, its sizes
 * and its category belong to the business; its price and whether it is
 * listed can differ per outlet (product_shop), and add-on questions are
 * defined per outlet (modifiers.shop_id). So the same dish reads differently
 * at two outlets, and each outlet gets its own event.
 *
 * Always the whole item - the contract makes ERP's copy a replace - read
 * straight from the database with every global scope off: this runs for
 * whichever outlet is named, not whichever one a signed-in person has
 * selected, and in the inbox and the API nobody is signed in at all.
 *
 * Where CRM says more than the contract can hold, it is narrowed here:
 *
 *   food_type   CRM also knows vegan and jain; both are vegetarian, which is
 *               what the contract's "veg" means.
 *   add-ons     a negative add-on price ("no cheese, -Rs 20") is sent as 0,
 *               because the contract's price is nonNegativePaise.
 *   price       the outlet's shelf price (selling_price, or this outlet's
 *               override), not a channel price or a running price list.
 */
final class MenuItemPayload
{
    private const FOOD_TYPES = [
        'veg' => 'veg',
        'vegan' => 'veg',
        'jain' => 'veg',
        'egg' => 'egg',
        'non_veg' => 'non_veg',
    ];

    /**
     * @return array<string, mixed>
     */
    public function for(Product $product, Shop $shop): array
    {
        $override = DB::table('product_shop')
            ->where('product_id', $product->id)
            ->where('shop_id', $shop->id)
            ->first(['selling_price', 'is_active']);

        // A null override means "inherit", exactly as Product::sellingPriceFor() reads it.
        $price = $override?->selling_price ?? $product->selling_price;

        return [
            'item_id' => PublicIds::of($product),
            'name' => Wire::name($product->name) ?? 'Item',
            'category' => $this->category($product),
            'food_type' => self::FOOD_TYPES[$product->food_type] ?? null,
            'active' => (bool) $product->is_active && (bool) ($override?->is_active ?? true),
            'price_paise' => Wire::nonNegativePaise($price),
            'sku' => Wire::text($product->sku, 64),
            'barcode' => Wire::text($product->barcode, 64),
            'sizes' => $this->sizes($product),
            'addons' => $this->addons($product, $shop),
        ];
    }

    /**
     * @return array{category_id: string, name: string}|null
     */
    private function category(Product $product): ?array
    {
        if ($product->category_id === null) {
            return null;
        }

        $category = Category::query()->withoutGlobalScopes()->find($product->category_id, ['id', 'ulid', 'name']);

        if ($category === null) {
            return null;
        }

        return [
            'category_id' => PublicIds::of($category),
            'name' => Wire::name($category->name) ?? 'Menu',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sizes(Product $product): array
    {
        return ProductVariant::query()
            ->where('product_id', $product->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (ProductVariant $variant) => [
                'variant_id' => PublicIds::of($variant),
                'name' => Wire::name($variant->name) ?? 'Size',
                'sku' => Wire::text($variant->sku, 64),
                // A size carries no barcode of its own in CRM.
                'barcode' => null,
                'price_paise' => Wire::nonNegativePaise($variant->price),
                'is_default' => (bool) $variant->is_default,
            ])
            ->values()
            ->all();
    }

    /**
     * Every answer to every question this outlet asks about the dish -
     * switched-off ones included, marked inactive, so an option a past order
     * line names is never one ERP has not heard of.
     *
     * @return list<array<string, mixed>>
     */
    private function addons(Product $product, Shop $shop): array
    {
        $order = DB::table('modifier_product')
            ->where('product_id', $product->id)
            ->pluck('sort_order', 'modifier_id');

        if ($order->isEmpty()) {
            return [];
        }

        $modifiers = Modifier::query()
            ->withoutGlobalScopes()
            ->where('shop_id', $shop->id)
            ->whereIn('id', $order->keys())
            ->with('options')
            ->get()
            ->sortBy([
                fn (Modifier $m) => (int) $order[$m->id],
                fn (Modifier $m) => (int) $m->sort_order,
                fn (Modifier $m) => $m->id,
            ]);

        $addons = [];

        foreach ($modifiers as $modifier) {
            foreach ($modifier->options as $option) {
                /** @var ModifierOption $option */
                $addons[] = [
                    'option_id' => PublicIds::of($option),
                    'modifier_id' => PublicIds::of($modifier),
                    'modifier_name' => Wire::name($modifier->name) ?? 'Add-ons',
                    'name' => Wire::name($option->name) ?? 'Option',
                    'price_paise' => Wire::nonNegativePaise($option->price),
                    'active' => (bool) $modifier->is_active && (bool) $option->is_available,
                ];
            }
        }

        return $addons;
    }
}
