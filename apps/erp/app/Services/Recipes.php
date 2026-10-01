<?php

namespace App\Services;

use App\Models\Recipe;
use App\Models\SalesAddon;
use App\Models\SalesItem;
use App\Models\SalesItemSize;
use App\Models\StockItem;
use App\Support\Units;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Khana\Kit\Support\Decimal;
use RuntimeException;

/**
 * Recipes: what a dish, an add-on or a prepared item is made of.
 *
 * The rules, from the architecture:
 *
 *   - Sizes replace. A size with a recipe of its own uses that; a size
 *     without one uses the recipe the whole dish shares.
 *   - Add-ons add. An option ordered with a dish puts its own recipe on top.
 *   - Packaged goods are a one-line recipe. A cola on the menu is one piece
 *     of the cola in the store, linked by barcode without anybody asking.
 *
 * Saving a recipe is also the moment a dish gets a cost and can run out, so
 * every save and delete tells App\Services\Availability to look again.
 */
class Recipes
{
    public function __construct(
        private readonly StockService $stock,
        private readonly Availability $availability,
    ) {}

    /* ------------------------------------------------------------ write */

    /**
     * Write (or rewrite) the recipe for one target.
     *
     * @param  array{sales_item_id?: int, sales_item_size_id?: int, sales_addon_id?: int, stock_item_id?: int}  $target
     * @param  list<array{item: StockItem, qty: int, entered_qty: string, unit: string, optional?: bool}>  $lines
     */
    public function save(string $outletId, array $target, array $lines, ?int $yieldQty = null, ?string $notes = null): Recipe
    {
        $target = array_map('intval', $target) + ['sales_item_id' => 0, 'sales_item_size_id' => 0, 'sales_addon_id' => 0, 'stock_item_id' => 0];

        if (count(array_filter([$target['sales_item_id'], $target['sales_addon_id'], $target['stock_item_id']])) !== 1) {
            throw new RuntimeException('A recipe is for one dish, one add-on or one prepared item.');
        }

        if ($lines === []) {
            throw new RuntimeException('A recipe has at least one ingredient.');
        }

        foreach ($lines as $line) {
            if ($line['item']->id === $target['stock_item_id']) {
                throw new RuntimeException($line['item']->name.' cannot be an ingredient of itself.');
            }
        }

        if ($target['stock_item_id'] > 0 && ($yieldQty === null || $yieldQty <= 0)) {
            throw new RuntimeException('Say how much one batch of this recipe makes.');
        }

        return DB::transaction(function () use ($outletId, $target, $lines, $yieldQty, $notes) {
            $recipe = Recipe::allOutlets()->updateOrCreate(
                ['outlet_id' => $outletId] + $target,
                ['yield_qty' => $target['stock_item_id'] > 0 ? $yieldQty : Units::SCALE, 'notes' => $notes, 'user_id' => Auth::id()],
            );

            $recipe->lines()->delete();

            foreach ($lines as $line) {
                $recipe->lines()->create([
                    'stock_item_id' => $line['item']->id,
                    'qty' => $line['qty'],
                    'entered_qty' => $line['entered_qty'],
                    'entered_unit' => $line['unit'],
                    'is_optional' => (bool) ($line['optional'] ?? false),
                ]);
            }

            $this->availability->recipeChanged($recipe);

            return $recipe->load('lines.item');
        });
    }

    public function delete(Recipe $recipe): void
    {
        DB::transaction(function () use ($recipe) {
            $recipe->lines()->delete();
            $recipe->delete();

            $this->availability->recipeChanged($recipe);
        });
    }

    /**
     * Link a packaged sales item to the stock item with the same barcode.
     *
     * A cola on the menu and a cola in the store are the same bottle. When
     * the barcodes agree and nobody has written a recipe, ERP writes the
     * obvious one: one piece.
     */
    public function linkByBarcode(SalesItem $item): void
    {
        $item->loadMissing('sizes');

        $candidates = $item->sizes->where('is_active', true)->isEmpty()
            ? [[0, $item->barcode]]
            : $item->sizes->where('is_active', true)->map(fn (SalesItemSize $size) => [$size->id, $size->barcode])->all();

        foreach ($candidates as [$sizeId, $barcode]) {
            if (blank($barcode) || $this->find($item->outlet_id, $item->id, $sizeId) !== null) {
                continue;
            }

            $stockItem = StockItem::allTenants()
                ->where('tenant_id', $item->tenant_id)
                ->where('barcode', $barcode)
                ->where('base_unit', 'pc')
                ->where('is_active', true)
                ->first();

            if ($stockItem === null) {
                continue;
            }

            $this->save($item->outlet_id, ['sales_item_id' => $item->id, 'sales_item_size_id' => $sizeId], [[
                'item' => $stockItem, 'qty' => Units::SCALE, 'entered_qty' => '1', 'unit' => 'pc',
            ]], notes: 'Linked by barcode');
        }
    }

    /* ------------------------------------------------------------- read */

    /**
     * The recipe a dish is cooked by: the size's own, or the one every size
     * shares. Null when nobody has written either.
     */
    public function find(string $outletId, int $salesItemId, int $sizeId = 0): ?Recipe
    {
        if ($salesItemId === 0) {
            return null;
        }

        return Recipe::allOutlets()
            ->with('lines')
            ->where('outlet_id', $outletId)
            ->where('sales_item_id', $salesItemId)
            ->whereIn('sales_item_size_id', array_unique([$sizeId, 0]))
            ->orderByDesc('sales_item_size_id')
            ->first();
    }

    public function forPrepared(string $outletId, int $stockItemId): ?Recipe
    {
        return Recipe::allOutlets()->with('lines.item')
            ->where('outlet_id', $outletId)->where('stock_item_id', $stockItemId)->first();
    }

    /**
     * Everything a number of portions of a dish takes out of the store,
     * add-ons included.
     *
     * @param  list<string>  $optionRefs  the add-on options ordered with it
     * @param  int  $portions  in ten-thousandths: two portions is 20000
     * @return array<int, int> stock item id => quantity
     */
    public function ingredients(string $outletId, int $salesItemId, int $sizeId, array $optionRefs, int $portions, string $source = SalesItem::CRM): array
    {
        $needs = [];

        $recipes = collect([$this->find($outletId, $salesItemId, $sizeId)]);

        if ($optionRefs !== []) {
            $addons = SalesAddon::allOutlets()
                ->where('outlet_id', $outletId)->where('source', $source)->whereIn('ref', $optionRefs)
                ->pluck('id');

            $recipes = $recipes->merge(
                Recipe::allOutlets()->with('lines')->where('outlet_id', $outletId)->whereIn('sales_addon_id', $addons)->get()
            );
        }

        foreach ($recipes->filter() as $recipe) {
            foreach ($recipe->lines as $line) {
                $needs[$line->stock_item_id] = ($needs[$line->stock_item_id] ?? 0)
                    + Decimal::mulDiv($line->qty, $portions, Units::SCALE);
            }
        }

        return array_filter($needs);
    }

    /**
     * What one portion (or one batch, for a prepared item) costs to make at
     * today's stock cost.
     */
    public function cost(Recipe $recipe): int
    {
        $recipe->loadMissing('lines.item');

        return (int) $recipe->lines->sum(fn ($line) => $line->item === null
            ? 0
            : Units::value($line->qty, $this->stock->costRate($recipe->outlet_id, $line->item)));
    }

    /**
     * Every active dish and size nobody has written a recipe for.
     *
     * @return Collection<int, array{item: SalesItem, size: ?SalesItemSize}>
     */
    public function needsRecipe(string $outletId): Collection
    {
        $have = Recipe::allOutlets()->where('outlet_id', $outletId)->where('sales_item_id', '>', 0)
            ->get(['sales_item_id', 'sales_item_size_id'])
            ->groupBy('sales_item_id')
            ->map(fn ($rows) => $rows->pluck('sales_item_size_id')->all());

        $missing = collect();

        SalesItem::allOutlets()->with('sizes')->where('outlet_id', $outletId)->active()->orderBy('name')->get()
            ->each(function (SalesItem $item) use ($have, $missing) {
                $covered = $have[$item->id] ?? [];

                // A recipe the whole dish shares covers every size of it.
                if (in_array(0, $covered, true)) {
                    return;
                }

                $sizes = $item->sizes->where('is_active', true);

                if ($sizes->isEmpty()) {
                    $missing->push(['item' => $item, 'size' => null]);

                    return;
                }

                foreach ($sizes as $size) {
                    if (! in_array($size->id, $covered, true)) {
                        $missing->push(['item' => $item, 'size' => $size]);
                    }
                }
            });

        return $missing;
    }
}
