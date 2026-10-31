<?php

namespace App\Models\Concerns;

use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * The same boundary as OwnTenantScope, for tables that only know a user.
 *
 * Activity logs, login histories, sessions and blocked addresses carry a
 * user_id rather than a tenant_id. A company's staff see the rows written by
 * their own company's accounts; rows with no user (system events, a login
 * attempt for an address nobody owns, an address blocked for everybody) are
 * the platform's and are not theirs to read or lift.
 *
 * Untouched for nobody signed in and for Super Admin, for the reasons given
 * on OwnTenantScope.
 */
final class OwnTenantUsersScope implements Scope
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

        $column = $model->qualifyColumn('user_id');

        /*
         | Your own rows are always yours. An account with no company named on
         | it (platform staff, or one TenantSeeder has not adopted yet) resolves
         | to the sole company for everything else, but its own user row is not
         | in that company - so without this it could not see its own sessions
         | or login history, while seeing everybody else's.
         */
        $builder->where(fn (Builder $q) => $q
            ->whereIn($column, User::query()
                ->withoutGlobalScopes()
                ->whereIn('tenant_id', CurrentTenant::accessibleIds() ?: [0])
                ->select('id'))
            ->orWhere($column, $user->getKey()));
    }
}
