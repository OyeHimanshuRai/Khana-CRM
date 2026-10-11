<?php

namespace App\Support;

use App\Models\Shop;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What ERP's stock has taken off each outlet's menu.
 *
 * ---------------------------------------------------------------------------
 * Two reasons a dish is off, kept apart
 * ---------------------------------------------------------------------------
 *
 *   staff said so   products.is_sold_out and product_variants.is_available,
 *                   thrown by hand and true for the whole business.
 *   stock said so   a row in product_stock_outs: ERP judged that one outlet's
 *                   stock cannot cover a portion of the dish, or of one size
 *                   of it. This class is the only reader and writer of those
 *                   rows.
 *
 * They are separate because they are different facts. Koramangala running out
 * of paneer says nothing about Indiranagar, and a dish a chef took off by
 * hand must not come back because a delivery arrived.
 *
 * ---------------------------------------------------------------------------
 * The rule
 * ---------------------------------------------------------------------------
 *
 * At one outlet, stock has a dish off the menu when there is a row for the
 * whole dish, or a row for every size staff have left on - a biryani whose
 * Half and Full are both out is out, though nothing says so in one row. A
 * size is off when it has a row of its own.
 *
 * No row means ERP has said nothing, which is "on sale". So does asking about
 * no outlet in particular: the consolidated All-shops view has no single
 * outlet's stock to answer from, and only the staff flag applies there.
 *
 * ---------------------------------------------------------------------------
 * Read once
 * ---------------------------------------------------------------------------
 *
 * A menu grid asks about hundreds of dishes. An outlet's rows are read the
 * first time anything asks and answered from memory after that - two queries
 * however long the menu is. What is remembered lives on the one instance the
 * container holds for the request (scoped in ErpIntegrationServiceProvider),
 * so a queue worker starts every job with nothing remembered and so does
 * every test. Every write here forgets the outlet it touched.
 *
 * The methods are static so a model or a view can ask without being handed
 * anything; called on an instance they do the same thing.
 *
 * ---------------------------------------------------------------------------
 * Nothing goes back to ERP
 * ---------------------------------------------------------------------------
 *
 * These rows are written with the query builder, not through a model, so no
 * menu hook fires - and neither they nor the staff flags are part of
 * crm.menu.item_upserted. A stock-out ERP sent is never echoed to it.
 */
final class StockOuts
{
    /** The product_variant_id of a row about the whole dish. */
    public const WHOLE_DISH = 0;

    private const TABLE = 'product_stock_outs';

    /**
     * Each outlet's rows as last read, keyed by shop id:
     *
     *   whole   dish id => true                 a row for the whole dish
     *   sizes   dish id => [size id => name]    a row for a size that still exists
     *   open    dish id => [size id, ...]       the sizes staff have left on,
     *                                           for the dishes in `sizes`
     *
     * @var array<int, array{whole: array<int, true>, sizes: array<int, array<int, string>>, open: array<int, list<int>>}>
     */
    private array $outlets = [];

    /* ------------------------------------------------------------ reading */

    /** Has stock taken the whole dish off this outlet's menu? */
    public static function dishOut(?int $shopId, int $productId): bool
    {
        if ($shopId === null) {
            return false;
        }

        $rows = self::rowsAt($shopId);

        if (isset($rows['whole'][$productId])) {
            return true;
        }

        $open = $rows['open'][$productId] ?? [];

        // Sizes staff switched off are theirs to answer for. With none left
        // on, stock has nothing to say about the dish.
        if ($open === []) {
            return false;
        }

        foreach ($open as $sizeId) {
            if (! isset($rows['sizes'][$productId][$sizeId])) {
                return false;
            }
        }

        return true;
    }

    /** Has stock taken this one size off this outlet's menu? */
    public static function sizeOut(?int $shopId, int $productId, int $variantId): bool
    {
        return $shopId !== null && isset(self::rowsAt($shopId)['sizes'][$productId][$variantId]);
    }

    /**
     * The sizes of a dish stock has taken off at this outlet, by name, in
     * menu order - for a screen that has to say which.
     *
     * @return list<string>
     */
    public static function sizesOut(?int $shopId, int $productId): array
    {
        return $shopId === null ? [] : array_values(self::rowsAt($shopId)['sizes'][$productId] ?? []);
    }

    /**
     * Every dish on the menu that stock has touched at this outlet, by name.
     *
     * `sizes` names the sizes that are out; it is empty when the whole dish
     * is. Dishes that have been archived or switched off are left out: they
     * are not on anybody's menu, whatever stock says about them.
     *
     * @return Collection<int, array{name: string, sizes: list<string>}>
     */
    public static function dishesOutAt(int $shopId): Collection
    {
        $rows = self::rowsAt($shopId);
        $ids = array_keys($rows['whole'] + $rows['sizes']);

        if ($ids === []) {
            return collect();
        }

        return DB::table('products')
            ->whereIn('id', $ids)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn (object $dish) => [
                'name' => (string) $dish->name,
                'sizes' => isset($rows['whole'][(int) $dish->id])
                    ? []
                    : array_values($rows['sizes'][(int) $dish->id] ?? []),
            ])
            ->values();
    }

    /* ------------------------------------------------------------ writing */

    /**
     * What one erp.availability.changed says: this dish (or, with a size,
     * only that size) ran out at this outlet, or came back.
     */
    public static function mark(int $shopId, int $productId, ?int $variantId, bool $available): void
    {
        $row = [
            'shop_id' => $shopId,
            'product_id' => $productId,
            'product_variant_id' => $variantId ?? self::WHOLE_DISH,
        ];

        if ($available) {
            DB::table(self::TABLE)->where($row)->delete();
        } else {
            // The unique index makes a repeat harmless, and keeps the time
            // it first ran out.
            DB::table(self::TABLE)->insertOrIgnore($row + ['created_at' => now(), 'updated_at' => now()]);
        }

        self::forget($shopId);
    }

    /**
     * Staff put a dish back on sale at this outlet against what stock says:
     * its rows go, the whole dish's and every size's.
     *
     * It stays on sale until ERP says otherwise again - the next time the
     * dish's stock changes, or the nightly check.
     *
     * @return int how many rows were removed
     */
    public static function putBack(int $shopId, int $productId): int
    {
        $removed = DB::table(self::TABLE)
            ->where('shop_id', $shopId)
            ->where('product_id', $productId)
            ->delete();

        self::forget($shopId);

        return $removed;
    }

    /**
     * Make this outlet's rows match what ERP holds - the nightly check.
     *
     * `$items` is the `items` list of ERP's GET /api/v1/outlets/{id}/signals:
     * each `['item_id' => ulid, 'variant_id' => ulid|null, 'available' =>
     * bool, 'cost_paise' => int|null]`. Afterwards the outlet has a row for
     * exactly the entries that say `available: false`, and no other - so a
     * dish staff put back by hand goes off again if ERP still has it out,
     * and a row whose "back in stock" event was lost is cleared.
     *
     * An id CRM does not know - a dish of another business, a size since
     * taken off the form - is passed over, as App\Events\Inbound\
     * MenuItemLookup passes over one in an event.
     *
     * @param  array<int, mixed>  $items
     * @return array{added: int, removed: int}
     */
    public static function reconcile(Shop $shop, array $items): array
    {
        $out = array_values(array_filter($items, fn (mixed $item) => is_array($item)
            && ($item['available'] ?? null) === false
            && is_string($item['item_id'] ?? null)));

        $dishes = $out === [] ? collect() : DB::table('products')
            ->where('tenant_id', $shop->tenant_id)
            ->whereIn('ulid', array_values(array_unique(array_column($out, 'item_id'))))
            ->pluck('id', 'ulid');

        $sizeIds = array_values(array_unique(array_filter(array_column($out, 'variant_id'), 'is_string')));

        $sizes = $sizeIds === [] ? collect() : DB::table('product_variants')
            ->whereIn('ulid', $sizeIds)
            ->get(['id', 'ulid', 'product_id'])
            ->keyBy('ulid');

        /** @var array<string, array{0: int, 1: int}> $wanted */
        $wanted = [];

        foreach ($out as $item) {
            $productId = $dishes[$item['item_id']] ?? null;

            if ($productId === null) {
                continue;
            }

            $variantId = self::WHOLE_DISH;

            if (($item['variant_id'] ?? null) !== null) {
                $size = is_string($item['variant_id']) ? ($sizes[$item['variant_id']] ?? null) : null;

                if ($size === null || (int) $size->product_id !== (int) $productId) {
                    continue;
                }

                $variantId = (int) $size->id;
            }

            $wanted[$productId.':'.$variantId] = [(int) $productId, $variantId];
        }

        $counts = DB::transaction(function () use ($shop, $wanted) {
            $held = DB::table(self::TABLE)
                ->where('shop_id', $shop->id)
                ->get(['id', 'product_id', 'product_variant_id'])
                ->keyBy(fn (object $row) => $row->product_id.':'.$row->product_variant_id);

            $add = array_diff_key($wanted, $held->all());
            $remove = $held->diffKeys($wanted);

            if ($add !== []) {
                $now = now();

                DB::table(self::TABLE)->insert(array_map(fn (array $pair) => [
                    'shop_id' => $shop->id,
                    'product_id' => $pair[0],
                    'product_variant_id' => $pair[1],
                    'created_at' => $now,
                    'updated_at' => $now,
                ], array_values($add)));
            }

            if ($remove->isNotEmpty()) {
                DB::table(self::TABLE)->whereIn('id', $remove->pluck('id')->all())->delete();
            }

            return ['added' => count($add), 'removed' => $remove->count()];
        });

        self::forget((int) $shop->id);

        return $counts;
    }

    /**
     * Forget what was read - one outlet's rows, or every outlet's.
     *
     * Every write above does this for the outlet it touched. A test that
     * writes the table directly has to say so itself.
     */
    public static function forget(?int $shopId = null): void
    {
        $memo = self::memo();

        if ($shopId === null) {
            $memo->outlets = [];
        } else {
            unset($memo->outlets[$shopId]);
        }
    }

    /* ----------------------------------------------------------- internal */

    /**
     * @return array{whole: array<int, true>, sizes: array<int, array<int, string>>, open: array<int, list<int>>}
     */
    private static function rowsAt(int $shopId): array
    {
        $memo = self::memo();

        return $memo->outlets[$shopId] ??= self::read($shopId);
    }

    /**
     * One outlet's rows, and - only for the dishes with a size out - which
     * sizes exist and which of them staff have left on.
     *
     * @return array{whole: array<int, true>, sizes: array<int, array<int, string>>, open: array<int, list<int>>}
     */
    private static function read(int $shopId): array
    {
        $whole = [];
        $sized = [];

        foreach (DB::table(self::TABLE)->where('shop_id', $shopId)->get(['product_id', 'product_variant_id']) as $row) {
            if ((int) $row->product_variant_id === self::WHOLE_DISH) {
                $whole[(int) $row->product_id] = true;
            } else {
                $sized[(int) $row->product_id][(int) $row->product_variant_id] = true;
            }
        }

        $sizes = [];
        $open = [];

        if ($sized !== []) {
            $variants = DB::table('product_variants')
                ->whereIn('product_id', array_keys($sized))
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'product_id', 'name', 'is_available']);

            foreach ($variants as $variant) {
                $dish = (int) $variant->product_id;
                $id = (int) $variant->id;

                // A row whose size has since been taken off the form matches
                // nothing here, and so says nothing.
                if (isset($sized[$dish][$id])) {
                    $sizes[$dish][$id] = (string) $variant->name;
                }

                if ((bool) $variant->is_available) {
                    $open[$dish][] = $id;
                }
            }
        }

        return ['whole' => $whole, 'sizes' => $sizes, 'open' => $open];
    }

    /** This request's copy - see "Read once" above. */
    private static function memo(): self
    {
        return app(self::class);
    }
}
