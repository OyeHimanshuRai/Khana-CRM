<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

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
                /*
                 | The company in context, or the only one there is.
                 |
                 | The fallback is what makes seeders and installers work.
                 | They run with no actor, so CurrentTenant::id() is null, and
                 | on a fresh single-business install that would have left the
                 | seeded units, tax slabs and demo menu owned by nobody -
                 | visible to a Super Admin and to no customer, which on day
                 | one means a till with no tax rate to pick.
                 |
                 | It guesses only where there is nothing to guess: with two
                 | companies on the install soleId() is null and the row is
                 | left unowned rather than filed under whichever came first.
                 | Same rule Shop::creating follows.
                 */
                $model->setAttribute('tenant_id', CurrentTenant::id() ?? CurrentTenant::soleId());
            }
        });

        /*
         | Last line of defence against a mass-assigned or hand-edited
         | tenant_id. Skipped without an authenticated user so seeders and
         | migrations can write freely.
         */
        static::saving(function (Model $model) {
            if (! Auth::hasUser()) {
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

    /** Read one named company regardless of what is currently selected. */
    public static function forTenant(int $tenantId): Builder
    {
        return static::allTenants()->where(
            (new static())->qualifyColumn('tenant_id'),
            $tenantId
        );
    }
}
