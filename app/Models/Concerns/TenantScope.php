<?php

namespace App\Models\Concerns;

use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * The company filter applied by App\Models\Concerns\BelongsToTenant.
 *
 * The tier above ShopScope, for masters that belong to a company rather than
 * to one of its branches - a stone list, a rate card, an invoice template.
 * Those are shared across every branch, so shop_id would be the wrong column
 * and a global table would be a leak.
 *
 * Most operational data does not need this. It filters on shop_id, and the
 * shops a user can reach are already narrowed to their tenant by CurrentShop
 * - one check standing in front of forty tables. This is only for the rows
 * that hang off the tenant directly.
 */
final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        /*
         | No actor: console, queue workers, seeders. Same reasoning as
         | ShopScope - a nightly job has to walk every company, and anything
         | that means one company must say so with forTenant().
         */
        if (! Auth::user() instanceof User) {
            /*
             | ...unless the request is a guest's at one restaurant: a table
             | sitting or a branch's storefront pins its company, and the menu
             | it reads must be that company's alone. A storefront customer
             | who has signed in is still that branch's guest, not staff -
             | `auth:customer` makes them the default guard's user, and they
             | have no tenant of their own to be scoped by.
             */
            if (($guest = CurrentTenant::guestId()) !== null) {
                $builder->where($model->qualifyColumn('tenant_id'), $guest);
            }

            return;
        }

        $column = $model->qualifyColumn('tenant_id');
        $tenantId = CurrentTenant::id();

        if ($tenantId !== null) {
            $builder->where($column, $tenantId);

            return;
        }

        /*
         | Null means one of two very different things, and they must not be
         | treated alike.
         |
         | A Super Admin in the consolidated view legitimately sees every
         | company - and also the rows not yet adopted by one, which on an
         | install that predates tenants is all of them. Filtering those out
         | would hide data from the only account able to assign it.
         |
         | Anyone else with no tenant has a broken account, and an unfiltered
         | read would hand them everything. They see nothing.
         */
        $user = Auth::user();

        if ($user instanceof User && $user->isSuperAdmin()) {
            return;
        }

        $builder->whereIn($column, CurrentTenant::accessibleIds() ?: [0]);
    }
}
