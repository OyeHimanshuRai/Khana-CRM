<?php

namespace App\Models\Concerns;

use App\Models\Shop;
use App\Support\CurrentShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Tenant scoping for every operational model.
 *
 * Applying this trait does three things:
 *
 *   1. Adds a global scope that narrows reads to the shop context resolved
 *      by App\Support\CurrentShop. This is the *default*, so forgetting to
 *      filter leaks nothing - the failure mode is an empty list, not another
 *      shop's data.
 *   2. Stamps shop_id on create when the caller did not set one.
 *   3. Refuses to save a row whose shop_id the actor may not write to.
 *
 * Escape hatches are deliberately explicit and ugly to type:
 * `Model::allShops()` for a genuinely cross-tenant read (consolidated
 * reports, the scheduler, queued jobs).
 *
 * Console and queue context has no authenticated user, so the scope steps
 * aside there entirely - a nightly reminder job has to see every shop. Any
 * job acting on one shop's behalf must say so with forShop().
 */
trait BelongsToShop
{
    public static function bootBelongsToShop(): void
    {
        static::addGlobalScope(new ShopScope());

        static::creating(function (Model $model) {
            if ($model->getAttribute('shop_id') === null) {
                $model->setAttribute('shop_id', CurrentShop::idForWrite());
            }
        });

        /*
         | Last line of defence. A mass-assigned shop_id, or a hand-edited
         | hidden field, must not be able to file a record into a shop the
         | actor cannot reach. Skipped without an authenticated user so
         | seeders and jobs can write freely.
         */
        static::saving(function (Model $model) {
            if (! Auth::hasUser()) {
                return;
            }

            $shopId = $model->getAttribute('shop_id');

            if ($shopId !== null && ! CurrentShop::canAccess((int) $shopId)) {
                throw new \RuntimeException(
                    'Refusing to save '.class_basename($model).' into shop '.$shopId.': not accessible to the current user.'
                );
            }
        });
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * Drop the tenant filter for this query.
     *
     * For consolidated reporting and for background work that legitimately
     * spans tenants. Every call site should be able to say why.
     */
    public static function allShops(): Builder
    {
        return static::query()->withoutGlobalScope(ShopScope::class);
    }

    /** Read one named shop regardless of what is currently selected. */
    public static function forShop(int $shopId): Builder
    {
        return static::allShops()->where(
            (new static())->qualifyColumn('shop_id'),
            $shopId
        );
    }

    /** Limit an existing query to the given shops. */
    public function scopeInShops(Builder $query, array $shopIds): Builder
    {
        return $query->whereIn($this->qualifyColumn('shop_id'), $shopIds);
    }
}
