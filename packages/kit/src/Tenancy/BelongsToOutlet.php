<?php

namespace Khana\Kit\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * For every model whose rows belong to one outlet.
 *
 *  1. Reads are narrowed by OutletScope - the default, so forgetting to
 *     filter leaks nothing.
 *  2. A new row is stamped with the outlet in context when none was given.
 *  3. Saving into an outlet the actor cannot reach is refused.
 *
 * The column is outlet_id unless the model says otherwise:
 *
 *   public const OUTLET_COLUMN = 'shop_id';
 *
 * Crossing the line deliberately is possible and ugly to type, on purpose:
 * allOutlets() for a genuinely cross-outlet read, forOutlet($id) for one
 * named outlet whatever is selected.
 */
trait BelongsToOutlet
{
    public static function bootBelongsToOutlet(): void
    {
        static::addGlobalScope(new OutletScope());

        static::creating(function (Model $model) {
            $column = $model->outletColumn();

            if ($model->getAttribute($column) === null) {
                $model->setAttribute($column, CurrentOutlet::idForWrite());
            }
        });

        static::saving(function (Model $model) {
            if (! Auth::hasUser()) {
                return;
            }

            $outlet = $model->getAttribute($model->outletColumn());

            if ($outlet !== null && ! CurrentOutlet::canAccess($outlet)) {
                throw OutletAccessDenied::outlet($model, $outlet);
            }
        });
    }

    public function outletColumn(): string
    {
        return defined(static::class.'::OUTLET_COLUMN') ? constant(static::class.'::OUTLET_COLUMN') : 'outlet_id';
    }

    public static function allOutlets(): Builder
    {
        return static::query()->withoutGlobalScope(OutletScope::class);
    }

    public static function forOutlet(int|string $outletId): Builder
    {
        $model = new static();

        return static::allOutlets()->where($model->qualifyColumn($model->outletColumn()), $outletId);
    }

    /**
     * @param  list<int|string>  $outletIds
     */
    public function scopeInOutlets(Builder $query, array $outletIds): Builder
    {
        return $query->whereIn($this->qualifyColumn($this->outletColumn()), $outletIds);
    }
}
