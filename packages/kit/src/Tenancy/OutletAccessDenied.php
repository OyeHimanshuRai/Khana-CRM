<?php

namespace Khana\Kit\Tenancy;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * A write aimed at an outlet or business the actor cannot reach - a
 * mass-assigned id, a hand-edited hidden field. The last line of defence,
 * and loud on purpose.
 */
final class OutletAccessDenied extends RuntimeException
{
    public static function outlet(Model $model, int|string $outletId): self
    {
        return new self('Refusing to save '.class_basename($model).' into outlet '.$outletId.': not accessible to the current user.');
    }

    public static function tenant(Model $model, int|string $tenantId): self
    {
        return new self('Refusing to save '.class_basename($model).' into business '.$tenantId.': not accessible to the current user.');
    }
}
