<?php

namespace App\Services;

use App\Events\StockMoved;
use App\Models\Recipe;
use App\Models\SalesItem;
use App\Models\StockItem;
use App\Support\Outlets;
use App\Support\Units;
use Illuminate\Support\Facades\DB;
use Khana\Kit\Events\Outbox;
use Khana\Kit\Support\Decimal;
use Throwable;

/**
 * What ERP tells CRM about a dish: whether stock can still cover a portion,
 * and what a portion costs.
 *
 *   - Sold-out follows stock. When what is on hand cannot cover one portion
 *     of a size, that size is marked unavailable in CRM; when a receipt
 *     brings it back, it is marked available again. Ingredients marked
 *     optional - a garnish - are ignored, and so are batches past their
 *     date unless the outlet says otherwise.
 *
 *   - Cost follows stock too. A portion's cost is its recipe at today's
 *     weighted-average cost, and CRM is told when it moves by 1% or more.
 *
 * Nothing calls this to say "check now". StockService announces every
 * movement (StockMoved) and Recipes announces every change; both are noted
 * here and looked at once, after the work that caused them has committed -
 * a receipt of twenty lines is one look, not twenty. Only a change is sent:
 * sales_item_states remembers what CRM was last told.
 *
 * Only dishes that came from CRM are reported on. An outlet that bills with
 * something else has nobody to tell.
 */
class Availability
{
    /** @var array<string, array<int, true>> outlet => stock item ids that moved */
    private array $moved = [];

    /** @var array<string, array<int, true>> outlet => sales item ids whose recipe changed */
    private array $rewritten = [];

    private bool $scheduled = false;

    public function __construct(
        private readonly StockService $stock,
        private readonly Outbox $outbox,
    ) {}

    public function stockMoved(StockMoved $event): void
    {
        $this->moved[$event->outletId][$event->stockItemId] = true;
        $this->schedule();
    }

    public function recipeChanged(Recipe $recipe): void
    {
        if ($recipe->sales_item_id > 0) {
            $this->rewritten[$recipe->outlet_id][$recipe->sales_item_id] = true;
            $this->schedule();
        }
    }

    /** Look at every dish of an outlet again - the nightly check, a first sync. */
    public function recheck(string $outletId): void
    {
        $ids = Recipe::allOutlets()->where('outlet_id', $outletId)->where('sales_item_id', '>', 0)
            ->distinct()->pluck('sales_item_id');

        DB::transaction(fn () => $this->look($outletId, $ids->all(), []));
    }

    /**
     * What stock can do for one dish right now, size by size - the panel on
     * CRM's menu screen, and ERP's own recipe page.
     *
     * @return list<array{size_id: int, variant_ref: ?string, size_name: ?string, has_recipe: bool, portions: ?int, cost_paise: ?int, price_paise: int, available: bool}>
     */
    public function snapshot(SalesItem $item): array
    {
        $outletId = $item->outlet_id;
        $settings = Outlets::settings($outletId);
        $usableOn = $settings->exclude_expired ? Outlets::businessDate($outletId) : null;
        $store = $settings->kitchen_only ? Outlets::stores($outletId)['kitchen'] : null;

        $recipes = Recipe::allOutlets()->with('lines.item')
            ->where('outlet_id', $outletId)->where('sales_item_id', $item->id)
            ->get()->keyBy('sales_item_size_id');

        $sizes = $item->sizes()->where('is_active', true)->orderBy('id')->get();
        $rows = [];

        foreach ($sizes->isEmpty() ? [null] : $sizes->all() as $size) {
            $recipe = $recipes[$size?->id ?? 0] ?? $recipes[0] ?? null;
            $portions = null;
            $cost = null;

            if ($recipe !== null) {
                $cost = 0;

                foreach ($recipe->lines as $line) {
                    if ($line->item === null) {
                        continue;
                    }

                    $cost += Units::value($line->qty, $this->stock->costRate($outletId, $line->item));

                    if (! $line->is_optional && $line->qty > 0) {
                        $covers = intdiv(max(0, $this->stock->onHand($outletId, $line->item, $store, $usableOn)) * Units::SCALE, $line->qty);
                        $portions = $portions === null ? $covers : min($portions, $covers);
                    }
                }
            }

            $rows[] = [
                'size_id' => $size?->id ?? 0,
                'variant_ref' => $size?->ref,
                'size_name' => $size?->name,
                'has_recipe' => $recipe !== null,
                'portions' => $portions,
                'cost_paise' => $cost,
                'price_paise' => $size?->price_paise ?? $item->price_paise,
                // With no recipe ERP has no opinion: the dish is on sale.
                'available' => $portions === null || $portions >= Units::SCALE,
            ];
        }

        return $rows;
    }

    /** Food cost as a percentage of the price, to two places; null with no price. */
    public static function percent(?int $costPaise, int $pricePaise): ?string
    {
        return $costPaise === null || $pricePaise <= 0
            ? null
            : Decimal::format(min(99_999, Decimal::mulDiv($costPaise, 10_000, $pricePaise)), 2);
    }

    /**
     * Look at everything noted since the last look.
     *
     * Runs after the commit of whatever moved the stock, in a transaction
     * of its own. A failure here is reported and swallowed: the receipt or
     * the kitchen line has already happened, and a missed signal is put
     * right by the next movement or the nightly recheck.
     */
    public function flush(): void
    {
        $moved = $this->moved;
        $rewritten = $this->rewritten;

        $this->moved = [];
        $this->rewritten = [];
        $this->scheduled = false;

        foreach (array_unique([...array_keys($moved), ...array_keys($rewritten)]) as $outletId) {
            $outletId = (string) $outletId;
            $stockItems = array_keys($moved[$outletId] ?? []);
            $recipeItems = array_keys($rewritten[$outletId] ?? []);

            $affected = $stockItems === [] ? [] : Recipe::allOutlets()
                ->where('outlet_id', $outletId)
                ->where('sales_item_id', '>', 0)
                ->whereHas('lines', fn ($q) => $q->whereIn('stock_item_id', $stockItems))
                ->distinct()
                ->pluck('sales_item_id')
                ->all();

            try {
                DB::transaction(fn () => $this->look($outletId, array_unique([...$affected, ...$recipeItems]), $recipeItems));
            } catch (Throwable $e) {
                if (app()->runningUnitTests()) {
                    throw $e;
                }

                report($e);
            }
        }
    }

    /* ---------------------------------------------------------- private */

    private function schedule(): void
    {
        if ($this->scheduled) {
            return;
        }

        $this->scheduled = true;

        DB::afterCommit(fn () => $this->flush());
    }

    /**
     * @param  list<int>  $salesItemIds
     * @param  list<int>  $rewritten  the ones looked at because their recipe changed
     */
    private function look(string $outletId, array $salesItemIds, array $rewritten): void
    {
        if ($salesItemIds === []) {
            return;
        }

        $settings = Outlets::settings($outletId);
        $usableOn = $settings->exclude_expired ? Outlets::businessDate($outletId) : null;
        $store = $settings->kitchen_only ? Outlets::stores($outletId)['kitchen'] : null;

        $onHand = [];
        $rates = [];

        $items = SalesItem::allOutlets()
            ->with(['sizes' => fn ($q) => $q->where('is_active', true)])
            ->where('outlet_id', $outletId)
            ->where('source', SalesItem::CRM)
            ->where('is_active', true)
            ->whereIn('id', $salesItemIds)
            ->get();

        foreach ($items as $item) {
            $recipes = Recipe::allOutlets()->with('lines.item')
                ->where('outlet_id', $outletId)->where('sales_item_id', $item->id)
                ->get()->keyBy('sales_item_size_id');

            $targets = $item->sizes->isEmpty() ? [null] : $item->sizes->all();

            foreach ($targets as $size) {
                $recipe = $recipes[$size?->id ?? 0] ?? $recipes[0] ?? null;

                $available = true;
                $cost = null;

                if ($recipe !== null) {
                    $cost = 0;

                    foreach ($recipe->lines as $line) {
                        $stockItem = $line->item;

                        if ($stockItem === null) {
                            continue;
                        }

                        $rates[$stockItem->id] ??= $this->stock->costRate($outletId, $stockItem);
                        $cost += Units::value($line->qty, $rates[$stockItem->id]);

                        if (! $line->is_optional) {
                            $onHand[$stockItem->id] ??= $this->stock->onHand($outletId, $stockItem, $store, $usableOn);
                            $available = $available && $onHand[$stockItem->id] >= $line->qty;
                        }
                    }
                }

                $this->tell($item, $size?->id ?? 0, $size?->ref, $size?->price_paise ?? $item->price_paise, $available, $cost, in_array($item->id, $rewritten, true));
            }
        }
    }

    private function tell(SalesItem $item, int $sizeId, ?string $variantRef, int $pricePaise, bool $available, ?int $cost, bool $rewritten): void
    {
        $key = ['sales_item_id' => $item->id, 'sales_item_size_id' => $sizeId];
        $state = DB::table('sales_item_states')->where($key)->first();

        // Until ERP has said otherwise, CRM has the dish on sale.
        $wasAvailable = $state === null ? true : (bool) $state->available;
        $lastCost = $state?->cost_paise;

        if ($available !== $wasAvailable) {
            $this->outbox->record('erp.availability.changed', [
                'item_id' => $item->ref,
                'variant_id' => $variantRef,
                'available' => $available,
                'reason' => $rewritten ? 'recipe_changed' : ($available ? 'restocked' : 'stock_out'),
            ], $item->tenant_id, $item->outlet_id);
        }

        $sendCost = $cost !== null && ($lastCost === null
            ? $cost > 0
            // A move of 1% or more of what CRM was last told.
            : abs($cost - (int) $lastCost) * 100 >= max(1, (int) $lastCost));

        if ($sendCost) {
            $this->outbox->record('erp.cost.changed', [
                'item_id' => $item->ref,
                'variant_id' => $variantRef,
                'cost_paise' => $cost,
                'food_cost_percent' => $pricePaise > 0
                    ? Decimal::format(min(99_999, Decimal::mulDiv($cost, 10_000, $pricePaise)), 2)
                    : null,
            ], $item->tenant_id, $item->outlet_id);
        }

        if ($state === null || $available !== $wasAvailable || $sendCost) {
            DB::table('sales_item_states')->updateOrInsert($key, [
                'outlet_id' => $item->outlet_id,
                'available' => $available,
                'cost_paise' => $sendCost ? $cost : $lastCost,
                'updated_at' => now(),
                'created_at' => $state->created_at ?? now(),
            ]);
        }
    }
}
