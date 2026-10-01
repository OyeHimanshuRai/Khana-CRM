<?php

namespace App\Models\Concerns;

use App\Support\Outlets;
use Illuminate\Database\Eloquent\Model;
use Khana\Kit\Tenancy\BelongsToOutlet;

/**
 * A row that belongs to one outlet.
 *
 * The kit's BelongsToOutlet does the work that matters: a signed-in person
 * only ever reads and writes the outlets Accounts gave them. This adds the
 * business the outlet belongs to, so nobody has to remember to set it.
 *
 * Code with no signed-in person - an event being applied, a nightly check -
 * is not scoped, and must name the outlet on every row it writes.
 */
trait InOutlet
{
    use BelongsToOutlet;

    public static function bootInOutlet(): void
    {
        static::creating(function (Model $model) {
            if ($model->getAttribute('tenant_id') === null && $model->getAttribute('outlet_id') !== null) {
                $model->setAttribute('tenant_id', Outlets::tenantOf($model->getAttribute('outlet_id')));
            }
        });
    }
}
