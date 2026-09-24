<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The menu as a guest sees it (§14).
 *
 * One place, because three screens will read it: the QR menu on a phone, the
 * POS when a captain takes an order at the table, and eventually the kiosk.
 * A customer menu that disagreed with the POS about whether breakfast is on
 * would be worse than either being wrong alone.
 *
 * Everything here is a read. Nothing in this class writes, which is what lets
 * a guest's page load be cached, retried and hit by twenty phones at once
 * without a thought.
 */
class MenuService
{
    /**
     * The whole card for one branch, grouped the way it is printed.
     *
     * Sold-out and out-of-window dishes are **kept and marked**, not filtered
     * out. A guest looking for the dish they had last week needs to see that
     * it is off today rather than conclude the restaurant stopped making it -
     * and a card that silently loses half its rows at 11:30 looks broken.
     *
     * Only `is_active` is a hard filter: that row has been withdrawn from the
     * menu altogether, which is a different statement.
     *
     * @return Collection<int, array{category: Category, items: Collection<int, Product>}>
     */
    public function card(Shop $shop, ?\DateTimeInterface $at = null): Collection
    {
        $items = $this->items($shop);

        if ($items->isEmpty()) {
            return collect();
        }

        $categories = $this->categories($items->pluck('category_id')->filter()->unique());

        /*
         | Grouped by the *parent* where there is one, with the sub-category
         | kept on the item for its sub-heading. A card reads "Main Course"
         | with "Indian Breads" inside it, not two top-level sections that
         | happen to be related.
         */
        $sections = $categories
            ->map(function (Category $category) use ($items, $categories) {
                $childIds = $categories
                    ->where('parent_id', $category->id)
                    ->pluck('id')
                    ->push($category->id);

                return [
                    'category' => $category,
                    'items' => $items->whereIn('category_id', $childIds)->values(),
                ];
            })
            // Only the top level becomes a section; children are folded in.
            ->filter(fn (array $section) => $section['category']->parent_id === null)
            ->filter(fn (array $section) => $section['items']->isNotEmpty())
            ->values();

        /*
         | Everything nobody filed.
         |
         | A dish with no category - or one whose category has been switched
         | off - would otherwise vanish from the card while still being
         | perfectly sellable, and a restaurant would lose the sale without
         | ever seeing an error. Given a heading of its own instead, which is
         | also a visible nudge to go and file it.
         |
         | Last, because it is the section nobody designed.
         */
        $placed = $sections->flatMap(fn (array $section) => $section['items']->pluck('id'));
        $orphans = $items->whereNotIn('id', $placed)->values();

        if ($orphans->isNotEmpty()) {
            $sections->push([
                'category' => $this->otherSection(),
                'items' => $orphans,
            ]);
        }

        return $sections;
    }

    /**
     * A heading for the dishes nobody filed.
     *
     * An unsaved Category rather than a special case in the view, so a
     * section is a section wherever it came from and the card needs no
     * branch. The id is 0, which no row has, so the view's anchors and its
     * grouping still work.
     */
    private function otherSection(): Category
    {
        $other = new Category(['name' => 'Also available']);

        $other->id = 0;
        $other->parent_id = null;

        return $other;
    }

    /**
     * Everything sellable, with what a card needs to draw a row.
     *
     * @return Collection<int, Product>
     */
    public function items(Shop $shop): Collection
    {
        return Product::query()
            /*
             | This branch's company, first of all.
             |
             | A guest has no session with us, so TenantScope steps aside and
             | this is the only thing standing between one restaurant's QR
             | code and every other restaurant's dishes on the install.
             */
            ->where('products.tenant_id', $shop->tenant_id)
            // Sellable, not merely active: flour is active and stocked and has
            // no business on a menu card.
            ->sellable()
            ->with([
                'variants' => fn ($q) => $q->orderBy('sort_order'),
                'modifiers' => fn ($q) => $q->where('is_active', true),
                'modifiers.options' => fn ($q) => $q->orderBy('sort_order'),
                'taxRate:id,rate',
            ])
            /*
             | A product withdrawn from *this branch* is gone from this
             | branch's card. The pivot carries that per-shop flag; a product
             | never customised for the shop has no pivot row and is on.
             */
            ->where(function (Builder $query) use ($shop) {
                $query->whereDoesntHave('shops', fn (Builder $q) => $q->where('shops.id', $shop->id))
                    ->orWhereHas('shops', fn (Builder $q) => $q
                        ->where('shops.id', $shop->id)
                        ->where('product_shop.is_active', true));
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * The categories those items sit in, plus their parents.
     *
     * Parents are pulled in even when nothing hangs off them directly: a
     * "Main Course" with dishes only in its children still has to appear, or
     * the card loses its headings.
     *
     * @param  Collection<int, int>  $ids
     * @return Collection<int, Category>
     */
    private function categories(Collection $ids): Collection
    {
        $direct = Category::query()
            ->active()
            ->whereIn('id', $ids)
            ->get();

        $parentIds = $direct->pluck('parent_id')->filter()->unique()->diff($direct->pluck('id'));

        $parents = $parentIds->isEmpty()
            ? collect()
            : Category::query()->active()->whereIn('id', $parentIds)->get();

        return $direct->concat($parents)
            ->unique('id')
            ->sortBy([['sort_order', 'asc'], ['name', 'asc']])
            ->values();
    }

    /**
     * What one dish costs a guest on this channel, before add-ons.
     *
     * A dish with sizes has no single price, so this answers with the size
     * the card opens on - which is what the row shows before anybody taps it.
     * The order line is priced from the size actually chosen; see the cart.
     */
    public function priceOf(Product $product, string $channel = 'dine_in', ?int $shopId = null): float
    {
        if ($product->relationLoaded('variants') && $product->variants->isNotEmpty()) {
            $opening = $product->variants->firstWhere('is_default', true)
                ?? $product->variants->first();

            return (float) $opening->price;
        }

        return $product->channelPriceFor($channel, $shopId);
    }

    /** "From ₹190" for a dish with sizes, "₹280" for one without. */
    public function priceLabel(Product $product, string $channel = 'dine_in', ?int $shopId = null): string
    {
        if ($product->relationLoaded('variants') && $product->variants->count() > 1) {
            $cheapest = (float) $product->variants->min('price');

            return 'From ₹'.number_format($cheapest, 2);
        }

        return '₹'.number_format($this->priceOf($product, $channel, $shopId), 2);
    }
}
