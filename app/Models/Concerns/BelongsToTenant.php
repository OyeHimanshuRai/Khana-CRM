<?php

namespace App\Models\Concerns;

use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

/**
 * Company scoping for masters that belong to the business, not to a branch.
 *
 * The same three jobs BelongsToShop does, one tier up:
 *
 *   1. A global scope narrowing reads to the company in context, so the
 *      failure mode of forgetting to filter is an empty list rather than
 *      another company's data.
 *   2. Stamps tenant_id on create when the caller did not set one.
 *   3. Refuses to save a row into a company the actor cannot reach.
 *
 * Use it for a master every branch of one business shares - stone types, rate
 * cards, invoice templates. Do *not* use it alongside BelongsToShop: a row
 * that already carries shop_id reaches its tenant through the shop, and a
 * second copy of the answer is a second thing to keep in step.
 *
 * Escape hatch: `Model::allTenants()`, deliberately awkward to type.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function (Model $model) {
            if ($model->getAttribute('tenant_id') === null) {
                $model->setAttribute('tenant_id', CurrentTenant::idForWrite());
            }
        });

        /*
         | Last line of defence against a mass-assigned or hand-edited
         | tenant_id. Skipped without an authenticated user so seeders and
         | migrations can write freely.
         */
        static::saving(function (Model $model) {
            // Staff only: a storefront customer has no tenants to check against.
            if (! Auth::user() instanceof User) {
                return;
            }

            $tenantId = $model->getAttribute('tenant_id');

            if ($tenantId !== null && ! CurrentTenant::canAccess((int) $tenantId)) {
                throw new \RuntimeException(
                    'Refusing to save '.class_basename($model).' into company '.$tenantId.': not accessible to the current user.'
                );
            }
        });
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Drop the company filter for this query.
     *
     * For the Super Admin's consolidated views and for background work that
     * legitimately spans companies. Every call site should be able to say why.
     */
    public static function allTenants(): Builder
    {
        return static::query()->withoutGlobalScope(TenantScope::class);
    }

    /**
     * `exists` for this table, limited to the company being written into.
     *
     * Validation rules query the table directly and never see a global
     * scope, so a bare `exists:products,id` would accept another company's
     * product id from a hand-edited form. Unrestricted only where there is
     * no company to restrict to: a Super Admin in the all-companies view.
     */
    public static function existsRule(string $column = 'id'): Exists
    {
        $rule = Rule::exists((new static())->getTable(), $column);
        $tenantId = CurrentTenant::idForWrite();

        return $tenantId === null ? $rule : $rule->where('tenant_id', $tenantId);
    }

    /** `unique` for this table, within the company being written into. */
    public static function uniqueRule(string $column): Unique
    {
        $rule = Rule::unique((new static())->getTable(), $column);
        $tenantId = CurrentTenant::idForWrite();

        return $tenantId === null ? $rule : $rule->where('tenant_id', $tenantId);
    }

    /**
     * Route binding on a storefront URL looks inside that branch's company.
     *
     * `/shop/{shop}/product/{product:slug}` binds the product before any
     * middleware has pinned the guest's tenant, and slugs are only unique
     * per company - so without this, "paneer-tikka" could resolve to another
     * restaurant's dish. The {shop} parameter is bound first and is already
     * a model by the time this runs.
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        $query = parent::resolveRouteBindingQuery($query, $value, $field);
        $shop = request()->route('shop');

        if ($shop instanceof Shop && ! Auth::user() instanceof User) {
            $query->where($this->qualifyColumn('tenant_id'), $shop->tenant_id);
        }

        return $query;
    }

    /** Read one named company regardless of what is currently selected. */
    public static function forTenant(int $tenantId): Builder
    {
        return static::allTenants()->where(
            (new static())->qualifyColumn('tenant_id'),
            $tenantId
        );
    }
}
