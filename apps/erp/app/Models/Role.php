<?php

namespace App\Models;

use App\Support\Roles;
use Illuminate\Database\Eloquent\Model;
use Khana\Kit\Tenancy\BelongsToTenant;

/**
 * A role a business made for itself - "Head Chef", "Night Storekeeper" -
 * copied from a preset and then trimmed or added to.
 *
 * Who holds it is Khana Accounts' to say, exactly as for a preset: this is
 * only what the role opens once somebody has it. Read through
 * App\Support\Roles, which keeps what it has looked up; saving or deleting a
 * role makes it look again.
 */
class Role extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'key', 'name', 'abilities', 'based_on', 'user_id'];

    protected function casts(): array
    {
        return ['abilities' => 'array'];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Roles::forget());
        static::deleted(fn () => Roles::forget());
    }
}
