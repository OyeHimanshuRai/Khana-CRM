<?php

namespace App\Models;

use App\Support\Roles;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Khana\Kit\Tenancy\Actor;

/**
 * Somebody working in ERP - a mirror of the person Accounts knows.
 *
 * Implements the kit's Actor, which is how the tenancy scopes learn whose
 * business and which outlets a query may see. Every answer comes from what
 * Accounts said at the last sign-in; the only thing kept locally is where
 * the outlet switcher was left.
 */
class User extends Authenticatable implements Actor
{
    protected $fillable = [
        'ulid', 'tenant_id', 'name', 'email', 'mobile', 'role_key',
        'is_platform_admin', 'outlet_ids', 'is_active',
    ];

    protected $hidden = ['remember_token'];

    protected function casts(): array
    {
        return [
            'is_platform_admin' => 'boolean',
            'outlet_ids' => 'array',
            'all_outlets' => 'boolean',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /** What this person's role lets them do - see App\Support\Roles. */
    public function allows(string $ability): bool
    {
        return $this->is_platform_admin || Roles::allows($this->role_key, $ability);
    }

    public function roleName(): string
    {
        return $this->is_platform_admin ? 'Platform staff' : Roles::name($this->role_key);
    }

    /* ------------------------------------------------------------ Actor */

    public function isPlatformAdmin(): bool
    {
        return (bool) $this->is_platform_admin;
    }

    public function tenantKey(): int|string|null
    {
        return $this->tenant_id;
    }

    public function assignedOutletKeys(): array
    {
        return array_values((array) $this->outlet_ids);
    }

    public function defaultOutletKey(): int|string|null
    {
        return $this->assignedOutletKeys()[0] ?? null;
    }

    public function rememberedTenantKey(): int|string|null
    {
        return $this->current_tenant_id;
    }

    public function rememberedOutletKey(): int|string|null
    {
        return $this->current_outlet_id;
    }

    public function prefersAllOutlets(): bool
    {
        return (bool) $this->all_outlets;
    }

    public function rememberContext(int|string|null $tenantKey, int|string|null $outletKey, bool $allOutlets): void
    {
        $this->forceFill([
            'current_tenant_id' => $tenantKey,
            'current_outlet_id' => $outletKey,
            'all_outlets' => $allOutlets,
        ])->saveQuietly();
    }
}
