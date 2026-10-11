<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Khana\Kit\Support\Ulid;

/**
 * The ULID other services know this row by.
 *
 * Numeric ids stay inside CRM - the schema, the routes, every foreign key.
 * Anything that leaves, such as a crm.menu.* event or an erp.availability.*
 * one coming back in, carries `ulid` instead, so ERP never depends on how
 * this database numbers its rows. Mirrors
 * apps/accounts/app/Models/Concerns/HasPublicId.php so the same idea reads
 * the same way in both services.
 *
 * @property string $ulid
 */
trait HasPublicId
{
    protected static function bootHasPublicId(): void
    {
        static::creating(function ($model) {
            $model->ulid ??= Ulid::generate();
        });
    }

    public static function findByUlid(?string $ulid): ?static
    {
        return Ulid::isValid($ulid) ? static::query()->where('ulid', $ulid)->first() : null;
    }

    /** @param  Builder<static>  $query */
    public function scopeWhereUlid(Builder $query, string $ulid): Builder
    {
        return $query->where('ulid', $ulid);
    }
}
