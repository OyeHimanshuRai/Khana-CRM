<?php

namespace Khana\Kit\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Narrows every read of an outlet-owned model to the outlets in context.
 *
 * Three cases, and the unsafe one fails closed:
 *
 *  - Nobody signed in: console, queue workers, seeders and the public guest
 *    pages. They see everything, so anything that means one outlet must say
 *    so with forOutlet() - the nightly job genuinely walks every outlet.
 *  - Staff: the selected outlet, or in the consolidated view the outlets
 *    they may reach - never another business's.
 *  - Signed in as something that is not staff (a storefront customer, a
 *    token of some other kind): nothing. Guessing would be a leak.
 *
 * Forgetting to filter therefore shows an empty list, never somebody else's
 * takings.
 */
final class OutletScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! Auth::hasUser()) {
            return;
        }

        $context = app(Context::class);

        if ($context->actor() === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $column = $model->qualifyColumn($model->outletColumn());
        $outlet = $context->outletId();

        if ($outlet !== null) {
            $builder->where($column, $outlet);

            return;
        }

        $reachable = $context->accessibleOutlets();

        $reachable === []
            ? $builder->whereRaw('1 = 0')
            : $builder->whereIn($column, $reachable);
    }
}
