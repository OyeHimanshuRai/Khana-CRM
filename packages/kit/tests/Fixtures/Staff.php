<?php

namespace Khana\Kit\Tests\Fixtures;

use Illuminate\Foundation\Auth\User;
use Khana\Kit\Tenancy\Actor;

/**
 * A member of staff for tests, held in memory: which business, which
 * outlets, and what they last chose.
 */
class Staff extends User implements Actor
{
    public bool $platform = false;

    public int|string|null $tenant = null;

    /** @var list<int|string> */
    public array $outlets = [];

    public int|string|null $defaultOutlet = null;

    public int|string|null $chosenTenant = null;

    public int|string|null $chosenOutlet = null;

    public bool $all = false;

    /**
     * @param  list<int|string>  $outlets
     */
    public static function at(int|string|null $tenant, array $outlets, int|string|null $chosen = null): self
    {
        $staff = new self();
        $staff->id = random_int(1, PHP_INT_MAX);
        $staff->tenant = $tenant;
        $staff->outlets = $outlets;
        $staff->chosenOutlet = $chosen;

        return $staff;
    }

    public static function platformAdmin(int|string|null $chosenTenant = null): self
    {
        $staff = self::at(null, []);
        $staff->platform = true;
        $staff->chosenTenant = $chosenTenant;

        return $staff;
    }

    public function isPlatformAdmin(): bool
    {
        return $this->platform;
    }

    public function tenantKey(): int|string|null
    {
        return $this->tenant;
    }

    public function assignedOutletKeys(): array
    {
        return $this->outlets;
    }

    public function defaultOutletKey(): int|string|null
    {
        return $this->defaultOutlet;
    }

    public function rememberedTenantKey(): int|string|null
    {
        return $this->chosenTenant;
    }

    public function rememberedOutletKey(): int|string|null
    {
        return $this->chosenOutlet;
    }

    public function prefersAllOutlets(): bool
    {
        return $this->all;
    }

    public function rememberContext(int|string|null $tenantKey, int|string|null $outletKey, bool $allOutlets): void
    {
        $this->chosenTenant = $tenantKey;
        $this->chosenOutlet = $outletKey;
        $this->all = $allOutlets;
    }
}
