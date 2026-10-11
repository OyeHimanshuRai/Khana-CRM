<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Khana\Kit\Support\Ulid;

/**
 * The ULID other services know this row by.
 *
 * Numeric ids stay inside Accounts. Anything that leaves - an event, the
 * snapshot, a token's profile - carries `ulid`, so CRM and ERP never depend
 * on how this database numbers its rows.
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
