<?php

namespace App\Models\Concerns;

use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Keep a company's staff inside their own company.
 *
 * For tables with a tenant_id of their own (users, shops) that must stay
 * reachable by the platform's Super Admin whichever company they are looking
 * at. That is why this is not TenantScope: TenantScope also narrows a Super
 * Admin to the company they have switched into, which would hide a Super
 * Admin's own row from themselves.
 *
 * Applied only to somebody signed in who is not a Super Admin:
 *
 *   - nobody signed in (the login lookup, the storefront, console, queues):
 *     untouched, because there is no company to stay inside yet;
 *   - Super Admin: untouched;
 *   - anyone else: their own company, and nothing when they have none.
 *
 * Route model binding runs through the model's query, so this is also what
 * stops one company opening another's record by guessing its id.
 */
final class OwnTenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! Auth::hasUser()) {
            return;
        }

        $user = Auth::user();

        if (! $user instanceof User || $user->isSuperAdmin()) {
            return;
        }

        $builder->whereIn($model->qualifyColumn('tenant_id'), CurrentTenant::accessibleIds() ?: [0]);
    }
}
