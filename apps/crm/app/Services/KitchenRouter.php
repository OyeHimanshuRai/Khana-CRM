<?php

namespace App\Services;

use App\Models\Category;
use App\Models\KitchenStation;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Which station cooks this dish (§9).
 *
 * One class, because the answer is read in three places that must never
 * disagree: when a table's order is placed, when the POS sends a KOT, and on
 * the menu screen where somebody is checking their routing before service.
 *
 * ---------------------------------------------------------------------------
 * The order it reads, and why
 * ---------------------------------------------------------------------------
 *
 *   1. the dish's own station        the deliberate exception
 *   2. its category's                how routing is actually set
 *   3. its category's parent's       so "Main Course → Tandoor" covers the
 *                                    sub-sections under it without four rows
 *   4. the shop's default            so nothing is ever cooked by nobody
 *
 * Only step 4 can return null, and only for a branch with no stations at all.
 * That is read as "this shop does not work in stations" rather than as a
 * misconfiguration: a one-room kitchen has one screen and never sets any of
 * this up, and the KDS still shows it everything.
 *
 * ---------------------------------------------------------------------------
 * Resolved once, at placement
 * ---------------------------------------------------------------------------
 *
 * The station is copied onto the order line, exactly as the dish's name and
 * its price are. Re-filing a dish at nine must not move a ticket that is
 * already on a pass, and a preparation-time report run next month has to be
 * able to say which station was actually cooking - not which one would be
 * cooking today.
 */
class KitchenRouter
{
    /** @var Collection<int, KitchenStation>|null */
    private ?Collection $stations = null;

    /** @var Collection<int, Category>|null */
    private ?Collection $categories = null;

    private ?int $shopId = null;

    /**
     * The station for one dish, or null when the branch runs no stations.
     */
    public function stationFor(Product $product, ?int $shopId = null): ?KitchenStation
    {
        $this->load($shopId);

        if ($this->stations->isEmpty()) {
            return null;
        }

        $direct = $this->active($product->kitchen_station_id);

        if ($direct) {
            return $direct;
        }

        $category = $product->category_id
            ? $this->categories->firstWhere('id', $product->category_id)
            : null;

        if ($category) {
            $own = $this->active($category->kitchen_station_id);

            if ($own) {
                return $own;
            }

            $parent = $category->parent_id
                ? $this->categories->firstWhere('id', $category->parent_id)
                : null;

            if ($parent) {
                $inherited = $this->active($parent->kitchen_station_id);

                if ($inherited) {
                    return $inherited;
                }
            }
        }

        return $this->fallback();
    }

    /** Just the id, for writing straight onto a line. */
    public function stationIdFor(Product $product, ?int $shopId = null): ?int
    {
        return $this->stationFor($product, $shopId)?->id;
    }

    /**
     * The station a dish would go to, named - for the menu screen.
     *
     * Says where the answer came from, because "Tandoor" and "Tandoor,
     * inherited from Main Course" are different things to somebody auditing
     * their routing before a Friday service.
     */
    public function explain(Product $product, ?int $shopId = null): ?string
    {
        $this->load($shopId);

        $station = $this->stationFor($product, $shopId);

        if ($station === null) {
            return null;
        }

        if ((int) $product->kitchen_station_id === (int) $station->id) {
            return $station->name;
        }

        $category = $product->category_id
            ? $this->categories->firstWhere('id', $product->category_id)
            : null;

        if ($category && (int) $category->kitchen_station_id === (int) $station->id) {
            return $station->name.' — from '.$category->name;
        }

        $parent = $category?->parent_id
            ? $this->categories->firstWhere('id', $category->parent_id)
            : null;

        if ($parent && (int) $parent->kitchen_station_id === (int) $station->id) {
            return $station->name.' — from '.$parent->name;
        }

        return $station->name.' — default';
    }

    /**
     * Load the whole routing table once.
     *
     * A twelve-line order would otherwise be twenty-four queries at the exact
     * moment a guest is waiting on a page to submit. There are rarely more
     * than a handful of stations and a few dozen categories, so both are read
     * whole.
     *
     * Kept for the life of the instance, which is one request: routing that
     * changed mid-request would split one order across two configurations.
     */
    private function load(?int $shopId): void
    {
        if ($this->stations !== null && $this->shopId === $shopId) {
            return;
        }

        $this->shopId = $shopId;

        $this->stations = KitchenStation::query()
            ->when($shopId, fn ($q) => $q->where('shop_id', $shopId))
            ->active()
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $this->categories = $this->stations->isEmpty()
            ? collect()
            : Category::query()->get(['id', 'name', 'parent_id', 'kitchen_station_id']);
    }

    /** A station by id, but only if it is one this branch still runs. */
    private function active(?int $id): ?KitchenStation
    {
        return $id ? $this->stations->firstWhere('id', $id) : null;
    }

    private function fallback(): ?KitchenStation
    {
        return $this->stations->firstWhere('is_default', true) ?? $this->stations->first();
    }

    /** Drop the cache - for a caller that has just changed the routing. */
    public function forget(): void
    {
        $this->stations = null;
        $this->categories = null;
        $this->shopId = null;
    }
}
