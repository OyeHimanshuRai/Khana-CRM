<?php

namespace App\Models\Concerns;

use App\Support\CurrentShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * The tenant filter applied by App\Models\Concerns\BelongsToShop.
 *
 * A selected shop narrows to that shop. "All shops" narrows to the shops the
 * user may reach - which is what keeps the consolidated view from being a
 * way around the shop_user pivot.
 */
final class ShopScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        /*
         | No actor: console, queue workers, seeders. Those see everything by
         | design - the nightly reminder run has to walk every shop. Anything
         | tenant-specific in that context must say so with forShop().
         */
        if (! Auth::hasUser()) {
            return;
        }

        $column = $model->qualifyColumn('shop_id');
        $shopId = CurrentShop::id();

        if ($shopId !== null) {
            $builder->where($column, $shopId);

            return;
        }

        // An account authorised for no shop sees no operational data at all,
        // which is the correct reading of "not authorized for any shop". The
        // [0] keeps that an empty result rather than an unfiltered one.
        $builder->whereIn($column, CurrentShop::accessibleIds() ?: [0]);
    }
}
