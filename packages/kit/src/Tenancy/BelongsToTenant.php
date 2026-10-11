<?php

namespace Khana\Kit\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * For every model whose rows belong to a business as a whole rather than one
 * of its outlets - a menu category shared by every branch, say.
 *
 * The column is tenant_id unless the model sets TENANT_COLUMN.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function (Model $model) {
            $column = $model->tenantColumn();

            if ($model->getAttribute($column) === null) {
                $model->setAttribute($column, CurrentTenant::id());
            }
        });

        static::saving(function (Model $model) {
            if (! Auth::hasUser()) {
                return;
            }

            $tenant = $model->getAttribute($model->tenantColumn());

            if ($tenant !== null && ! CurrentTenant::canAccess($tenant)) {
                throw OutletAccessDenied::tenant($model, $tenant);
            }
        });
    }

    public function tenantColumn(): string
    {
        return defined(static::class.'::TENANT_COLUMN') ? constant(static::class.'::TENANT_COLUMN') : 'tenant_id';
    }

    public static function allTenants(): Builder
    {
        return static::query()->withoutGlobalScope(TenantScope::class);
    }

    public static function forTenant(int|string $tenantId): Builder
    {
        $model = new static();

        return static::allTenants()->where($model->qualifyColumn($model->tenantColumn()), $tenantId);
    }
}
