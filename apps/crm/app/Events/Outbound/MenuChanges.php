<?php

namespace App\Events\Outbound;

use App\Models\Product;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Collects which dishes changed during one save, and announces each once.
 *
 * A menu item is many rows - the dish, its sizes, this outlet's price, its
 * add-on questions - and the edit form writes all of them in one go. The
 * model hooks (MenuWatch) report every row as it is written; announcing on
 * each would send a dish four times, the first three describing a
 * half-saved item. So inside batch() the reports are only noted, and when
 * the outermost batch ends - still inside the caller's transaction - each
 * dish is sent once, whole, as it finally stands.
 *
 * Outside a batch every report is sent at once. That keeps a write nobody
 * wrapped (a seeder, tinker, a path added later) correct - the last event
 * for a dish is always its final state, which is all a replace needs - just
 * chattier.
 *
 * Scoped: one request's or one job's worth of notes, never shared.
 */
final class MenuChanges
{
    private int $depth = 0;

    /** @var array<int, true|array<int, true>> product id => every outlet (true), or the outlets named */
    private array $pending = [];

    public function __construct(private readonly CrmEvents $events) {}

    /**
     * A database transaction whose menu changes are announced once, at its end.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function transaction(Closure $work): mixed
    {
        return DB::transaction(fn () => $this->batch($work));
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function batch(Closure $work): mixed
    {
        $this->depth++;

        try {
            $result = $work();
        } catch (Throwable $e) {
            // The caller's transaction rolls back; what it changed never was.
            if (--$this->depth === 0) {
                $this->pending = [];
            }

            throw $e;
        }

        if (--$this->depth === 0) {
            $this->flush();
        }

        return $result;
    }

    /**
     * These dishes changed - at every outlet, or at the one named.
     *
     * @param  Product|int|iterable<int>  $products
     */
    public function changed(Product|int|iterable $products, ?int $shopId = null): void
    {
        $ids = match (true) {
            $products instanceof Product => [$products->id],
            is_int($products) => [$products],
            default => collect($products)->map(fn ($id) => (int) $id)->filter()->unique()->all(),
        };

        foreach ($ids as $id) {
            if ($this->depth === 0) {
                $this->events->menuItemUpserted($id, $shopId === null ? null : [$shopId]);

                continue;
            }

            if (($this->pending[$id] ?? null) === true) {
                continue;
            }

            if ($shopId === null) {
                $this->pending[$id] = true;
            } else {
                $this->pending[$id][$shopId] = true;
            }
        }
    }

    /**
     * Archived: said at once, and anything noted for the dish is dropped - an
     * upsert sent after the archive would read as the dish coming back.
     */
    public function archived(Product $product): void
    {
        unset($this->pending[$product->id]);

        $this->events->menuItemArchived($product);
    }

    private function flush(): void
    {
        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as $id => $shops) {
            $this->events->menuItemUpserted($id, $shops === true ? null : array_keys($shops));
        }
    }
}
