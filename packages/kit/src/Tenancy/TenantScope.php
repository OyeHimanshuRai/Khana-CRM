<?php

namespace Khana\Kit\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * The tier above OutletScope, for rows that belong to a business rather than
 * one of its outlets. Same three cases, same fail-closed answer for anyone
 * signed in who is not staff. Platform staff in the consolidated view see
 * every business; nobody else ever does.
 */
final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! Auth::hasUser()) {
            return;
        }

        $context = app(Context::class);
        $actor = $context->actor();

        if ($actor === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $column = $model->qualifyColumn($model->tenantColumn());
        $tenant = $context->tenantId();

        if ($tenant !== null) {
            $builder->where($column, $tenant);

            return;
        }

        if ($actor->isPlatformAdmin()) {
            return;
        }

        $reachable = $context->accessibleTenants();

        $reachable === []
            ? $builder->whereRaw('1 = 0')
            : $builder->whereIn($column, $reachable);
    }
}
