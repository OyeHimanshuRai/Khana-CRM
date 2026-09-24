<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\RecipeItem;
use App\Models\Shop;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Recipes, and the stock they consume (§10).
 *
 * ---------------------------------------------------------------------------
 * The deduction that was always the right one
 * ---------------------------------------------------------------------------
 *
 * A dish is marked made-to-order and moves no stock when it is sold, because a
 * restaurant has no count of Butter Naan. What it does have is a count of flour
 * and butter, and this is what takes them off the shelf.
 *
 * The consumption happens in the *kitchen*, not at the till: a dish that is
 * cooked and then comped, or sent back, or eaten by the staff, has used its
 * ingredients either way. Hanging it off the bill would mean a kitchen that
 * gave away four plates on a Friday showed no consumption for them.
 *
 * ---------------------------------------------------------------------------
 * Once, and never given back
 * ---------------------------------------------------------------------------
 *
 * `order_items.recipe_consumed_at` is the guard. A ticket recalled because a
 * plate came back cold has still used its ingredients - recalling it does not
 * un-cook the food - so the stamp is never cleared and a re-bumped line does
 * not consume twice.
 */
class RecipeService
{
    /** When a shop's kitchen is deemed to have used its ingredients. */
    public const OFF = 'off';

    public const ON_ACCEPTED = 'accepted';

    public const ON_READY = 'ready';

    public const ON_SERVED = 'served';

    /** @var array<string, string> */
    public const MOMENTS = [
        self::OFF => 'Never — this outlet does not track ingredients',
        self::ON_ACCEPTED => 'When the kitchen accepts the ticket',
        self::ON_READY => 'When the dish is ready',
        self::ON_SERVED => 'When the dish reaches the table',
    ];

    public function __construct(private readonly StockService $stock) {}

    /* ----------------------------------------------------------- reading */

    /**
     * What one dish is made of, for the size it was sold as.
     *
     * A row naming a variant **replaces** the generic rows for that size
     * rather than adding to them. Two half-recipes that had to be read
     * together would mean nobody could look at one screen and know what a
     * Full biryani actually contains - and the first person to add rice to
     * the Full without removing it from the shared list would double it.
     *
     * ---------------------------------------------------------------------
     * Why the shop is named rather than inherited
     * ---------------------------------------------------------------------
     *
     * A recipe belongs to one kitchen, but RecipeItem's ambient scope narrows
     * to *every shop the reader may see* when no single one is selected - see
     * ShopScope. In All-shops mode a two-branch chain would then get both
     * branches' rows for the same dish, and consume twice the flour and cost
     * twice the money.
     *
     * So every caller that knows which kitchen it means says so. Consumption
     * always does: it passes the order's own shop, never the reader's.
     *
     * @return Collection<int, RecipeItem>
     */
    public function componentsFor(Product $dish, ?int $variantId = null, ?int $shopId = null): Collection
    {
        $query = $shopId !== null
            ? RecipeItem::forShop($shopId)
            : RecipeItem::query();

        $all = $query
            ->forDish($dish->id)
            ->with(['ingredient.unit', 'ingredient.taxRate'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($variantId === null) {
            return $all->whereNull('product_variant_id')->values();
        }

        $specific = $all->where('product_variant_id', $variantId)->values();

        return $specific->isNotEmpty()
            ? $specific
            : $all->whereNull('product_variant_id')->values();
    }

    /**
     * What a dish costs to make, at what the shop pays for its ingredients.
     *
     * Null when there is no recipe: zero would read as "free", and a margin
     * report that showed a hundred percent on every un-costed dish is a report
     * nobody checks twice.
     */
    public function costOf(Product $dish, ?int $variantId = null, ?int $shopId = null): ?float
    {
        $components = $this->componentsFor($dish, $variantId, $shopId);

        if ($components->isEmpty()) {
            return null;
        }

        return round($components->sum(fn (RecipeItem $item) => $item->cost($shopId)), 2);
    }

    /**
     * What a page of dishes costs to make, in one query.
     *
     * The list screen shows a cost per row, and asking costOf() per row is a
     * query per row - twenty-five of them on a default page, plus their
     * ingredient loads. This reads every component for the whole page at once
     * and resolves the same variant rule in memory.
     *
     * @param  Collection<int, Product>  $dishes
     * @return array<int, float|null> dish id => cost, or null where there is
     *                                no recipe
     */
    public function costMap(Collection $dishes, ?int $shopId = null): array
    {
        $ids = $dishes->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        $query = $shopId !== null ? RecipeItem::forShop($shopId) : RecipeItem::query();

        $items = $query
            ->whereIn('product_id', $ids)
            ->whereNull('product_variant_id')
            ->with('ingredient')
            ->get()
            ->groupBy('product_id');

        $costs = [];

        foreach ($ids as $id) {
            $group = $items->get($id);

            // Null rather than zero: zero reads as "free", and a margin
            // report showing a hundred percent on every un-costed dish is a
            // report nobody checks twice.
            $costs[$id] = $group === null
                ? null
                : round($group->sum(fn (RecipeItem $item) => $item->cost($shopId)), 2);
        }

        return $costs;
    }

    /**
     * Dishes this ingredient is used in - asked before anybody deletes one.
     *
     * @return Collection<int, RecipeItem>
     */
    public function usedIn(Product $ingredient): Collection
    {
        return RecipeItem::query()
            ->where('ingredient_id', $ingredient->id)
            ->with(['product:id,name', 'variant:id,name'])
            ->get();
    }

    /* --------------------------------------------------------- consuming */

    /**
     * Take one order line's ingredients off the shelf.
     *
     * Returns the number of components consumed, or 0 when there was nothing
     * to do - no recipe, already consumed, or the outlet does not track
     * ingredients. A no-op is not an error: most of this system's lines are
     * one of those three.
     */
    public function consume(OrderItem $line, ?string $reason = null): int
    {
        if ($line->recipe_consumed_at !== null) {
            return 0;
        }

        $order = $line->order;
        $shop = $order?->shop;

        if ($order === null || $shop === null) {
            return 0;
        }

        if ($this->momentFor($shop) === self::OFF) {
            return 0;
        }

        $dish = $line->product;

        if ($dish === null) {
            return 0;
        }

        // The order's shop, never the reader's. See componentsFor().
        $components = $this->componentsFor($dish, $line->product_variant_id, $order->shop_id);

        if ($components->isEmpty()) {
            /*
             | Stamped anyway. A dish with no recipe has consumed everything it
             | was ever going to, and leaving the stamp null would make this
             | method re-ask the same question on every bump for the rest of
             | the evening.
             */
            $line->forceFill(['recipe_consumed_at' => now()])->save();

            return 0;
        }

        $warehouse = $this->kitchenStore($shop, $order->warehouse_id);

        if ($warehouse === null) {
            throw new RuntimeException('This shop has no store to take ingredients from.');
        }

        return DB::transaction(function () use ($line, $order, $shop, $components, $warehouse, $reason) {
            /*
             | Locked and re-read. Two stations bumping two lines of one ticket
             | in the same second is ordinary, and without this both could pass
             | the "not yet consumed" check and take the flour twice.
             */
            $locked = OrderItem::query()->whereKey($line->id)->lockForUpdate()->first();

            if ($locked === null || $locked->recipe_consumed_at !== null) {
                return 0;
            }

            $made = max(1.0, (float) $line->quantity);
            $taken = 0;

            foreach ($components as $component) {
                $ingredient = $component->ingredient;

                if ($ingredient === null) {
                    continue;
                }

                $quantity = round((float) $component->quantity * $made, 4);

                if ($quantity <= 0) {
                    continue;
                }

                /*
                 | Never refused for want of stock - StockService exempts
                 | CONSUMPTION from the availability guard, and the reasoning
                 | is written there. In short: the food has been cooked, and
                 | refusing to record it because the count disagrees leaves
                 | the shelf reading as full when it is empty.
                 */
                $this->stock->issue(
                    $ingredient,
                    $quantity,
                    $warehouse,
                    null,
                    StockMovement::CONSUMPTION,
                    $order,
                    $reason ?? sprintf('%s — %s', $order->order_number, $line->title()),
                    $shop->id,
                );

                $taken++;
            }

            $locked->forceFill(['recipe_consumed_at' => now()])->save();

            return $taken;
        });
    }

    /**
     * Consume every line on a ticket that has reached the shop's moment.
     *
     * For the caller that has just moved a whole ticket rather than one line.
     */
    public function consumeTicket(\App\Models\Order $order, ?string $reason = null): int
    {
        return (int) $order->items()
            ->whereNull('recipe_consumed_at')
            ->whereNotNull('kitchen_status')
            ->get()
            ->sum(fn (OrderItem $line) => $this->consume($line, $reason));
    }

    /**
     * Whether a line at this rung should consume now.
     *
     * The comparison is by position on the ladder, not by equality: a line
     * bumped straight from Placed to Ready has passed the accept moment
     * without ever sitting at it, and a check for equality would let its
     * ingredients go unrecorded forever.
     */
    public function shouldConsumeAt(Shop $shop, string $rung): bool
    {
        $moment = $this->momentFor($shop);

        if ($moment === self::OFF) {
            return false;
        }

        $ladder = [
            \App\Models\Order::PENDING,
            \App\Models\Order::CONFIRMED,
            \App\Models\Order::PREPARING,
            \App\Models\Order::READY,
            \App\Models\Order::SERVED,
        ];

        $wanted = match ($moment) {
            self::ON_ACCEPTED => \App\Models\Order::CONFIRMED,
            self::ON_SERVED => \App\Models\Order::SERVED,
            default => \App\Models\Order::READY,
        };

        $at = array_search($rung, $ladder, true);
        $target = array_search($wanted, $ladder, true);

        return $at !== false && $target !== false && $at >= $target;
    }

    /** What this outlet has chosen, defaulting to the dish being ready. */
    public function momentFor(Shop $shop): string
    {
        $moment = (string) ($shop->recipe_deduction ?: self::ON_READY);

        return array_key_exists($moment, self::MOMENTS) ? $moment : self::ON_READY;
    }

    /* ----------------------------------------------------------- writing */

    /**
     * Replace one dish's recipe, for one size or for all of them.
     *
     * Replaced rather than merged, because the screen shows the whole recipe
     * and somebody who removed a row meant to remove it. A merge would make
     * deleting an ingredient impossible from the only screen that lists them.
     *
     * @param  array<int, array{ingredient_id: int|string, quantity: float|string, note?: string|null}>  $rows
     */
    public function save(Product $dish, ?int $variantId, array $rows, ?int $shopId = null): int
    {
        $shopId ??= \App\Support\CurrentShop::idForWrite();

        if ($shopId === null) {
            throw new RuntimeException('Choose a single shop before editing a recipe — a recipe belongs to one kitchen.');
        }

        return DB::transaction(function () use ($dish, $variantId, $rows, $shopId) {
            RecipeItem::query()
                ->forDish($dish->id)
                ->where('product_variant_id', $variantId)
                ->delete();

            $order = 0;
            $kept = 0;
            $seen = [];

            foreach ($rows as $row) {
                $ingredientId = (int) ($row['ingredient_id'] ?? 0);
                $quantity = round((float) ($row['quantity'] ?? 0), 4);

                if ($ingredientId <= 0 || $quantity <= 0) {
                    // A blank row is somebody who added one and changed their
                    // mind, not an error worth stopping the save for.
                    continue;
                }

                if ($ingredientId === $dish->id) {
                    throw new RuntimeException('A dish cannot be an ingredient of itself.');
                }

                if (in_array($ingredientId, $seen, true)) {
                    throw new RuntimeException(
                        'That recipe lists the same ingredient twice. Add the quantities together instead.'
                    );
                }

                $seen[] = $ingredientId;

                RecipeItem::query()->create([
                    'shop_id' => $shopId,
                    'product_id' => $dish->id,
                    'product_variant_id' => $variantId,
                    'ingredient_id' => $ingredientId,
                    'quantity' => $quantity,
                    'note' => $row['note'] ?? null,
                    'sort_order' => $order++,
                ]);

                $kept++;
            }

            ActivityLog::record(
                'recipe.saved',
                sprintf('Recipe for "%s" saved — %d ingredient%s', $dish->name, $kept, $kept === 1 ? '' : 's'),
                $dish,
            );

            return $kept;
        });
    }

    /**
     * Where ingredients come off.
     *
     * The order's own warehouse when it has one, the shop's default otherwise.
     * A kitchen store is an ordinary warehouse - see DemoMenuSeeder, which
     * seeds one - so nothing here needs a concept the rest of the system does
     * not already have.
     */
    private function kitchenStore(Shop $shop, ?int $warehouseId): ?Warehouse
    {
        if ($warehouseId) {
            $named = Warehouse::query()->find($warehouseId);

            if ($named !== null) {
                return $named;
            }
        }

        return Warehouse::defaultFor($shop->id);
    }
}
