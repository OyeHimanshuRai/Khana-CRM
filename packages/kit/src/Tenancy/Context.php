<?php

namespace Khana\Kit\Tenancy;

use Illuminate\Support\Facades\Auth;

/**
 * Which business and which outlet this request is working in, and which it
 * may reach. The single place those questions are answered.
 *
 * Ported from the monolith's CurrentTenant and CurrentShop, renamed from shop
 * to outlet, with the same rules:
 *
 *  - Staff are pinned to their own business. Platform staff may choose one,
 *    or sit in the consolidated view (tenant null).
 *  - Which outlets someone may reach is data (what they were assigned),
 *    narrowed to the business in context - one check standing in front of
 *    every outlet-owned table.
 *  - A stored choice is re-checked every request: losing access to an outlet
 *    takes effect immediately, not the next time somebody switches.
 *  - "All outlets" is a real choice, kept apart from "never chose".
 *
 * Scoped to the request (and to each queued job), so no answer leaks from one
 * to the next.
 */
final class Context
{
    private ?array $tenants = null;

    private bool $tenantResolved = false;

    private int|string|null $tenantId = null;

    private ?array $outlets = null;

    private bool $outletResolved = false;

    private int|string|null $outletId = null;

    public function __construct(private readonly Directory $directory) {}

    /** The signed-in member of staff, or null for nobody - or for someone who is not staff. */
    public function actor(): ?Actor
    {
        $user = Auth::user();

        return $user instanceof Actor ? $user : null;
    }

    /* ------------------------------------------------------------ tenant */

    /** The business in context, or null for the consolidated view (platform staff only). */
    public function tenantId(): int|string|null
    {
        if (! $this->tenantResolved) {
            $this->tenantResolved = true;
            $this->tenantId = $this->resolveTenant();
        }

        return $this->tenantId;
    }

    /**
     * @return list<int|string>
     */
    public function accessibleTenants(): array
    {
        if ($this->tenants !== null) {
            return $this->tenants;
        }

        $actor = $this->actor();

        if ($actor === null) {
            return $this->tenants = [];
        }

        $all = $this->directory->tenantKeys();

        if ($actor->isPlatformAdmin()) {
            return $this->tenants = $all;
        }

        $own = $actor->tenantKey() ?? $this->soleTenant($all);

        return $this->tenants = ($own !== null && self::contains($all, $own)) ? [self::pick($all, $own)] : [];
    }

    public function canAccessTenant(int|string $tenantId): bool
    {
        return self::contains($this->accessibleTenants(), $tenantId);
    }

    /**
     * Switch business. Only platform staff have anywhere to switch to, and
     * leaving a business leaves its outlet too.
     */
    public function setTenant(int|string|null $tenantId): bool
    {
        $actor = $this->actor();

        if ($actor === null || ! $actor->isPlatformAdmin()) {
            return false;
        }

        if ($tenantId !== null && ! $this->canAccessTenant($tenantId)) {
            return false;
        }

        if ($tenantId === null && count($this->accessibleTenants()) < 2) {
            return false;
        }

        $tenantId = $tenantId === null ? null : self::pick($this->accessibleTenants(), $tenantId);

        $actor->rememberContext($tenantId, null, false);

        $this->tenantId = $tenantId;
        $this->tenantResolved = true;
        $this->forgetOutlet();

        return true;
    }

    /* ------------------------------------------------------------ outlet */

    /** The outlet in context, or null for the consolidated "All outlets" view. */
    public function outletId(): int|string|null
    {
        if (! $this->outletResolved) {
            $this->outletResolved = true;
            $this->outletId = $this->resolveOutlet();
        }

        return $this->outletId;
    }

    /**
     * @return list<int|string>
     */
    public function accessibleOutlets(): array
    {
        if ($this->outlets !== null) {
            return $this->outlets;
        }

        $actor = $this->actor();

        if ($actor === null) {
            return $this->outlets = [];
        }

        $tenant = $this->tenantId();

        if ($actor->isPlatformAdmin()) {
            return $this->outlets = $this->directory->outletKeys($tenant);
        }

        // Staff who belong to no business reach nothing - never "everything".
        if ($tenant === null) {
            return $this->outlets = [];
        }

        $assigned = $actor->assignedOutletKeys();

        return $this->outlets = array_values(array_filter(
            $this->directory->outletKeys($tenant),
            fn (int|string $outlet) => self::contains($assigned, $outlet),
        ));
    }

    public function canAccessOutlet(int|string $outletId): bool
    {
        return self::contains($this->accessibleOutlets(), $outletId);
    }

    /** Whether the consolidated view means anything: only with two outlets or more. */
    public function canSeeAllOutlets(): bool
    {
        return count($this->accessibleOutlets()) > 1;
    }

    /**
     * @param  int|string|null  $outletId  null chooses "All outlets"
     */
    public function setOutlet(int|string|null $outletId): bool
    {
        $actor = $this->actor();

        if ($actor === null) {
            return false;
        }

        if ($outletId !== null && ! $this->canAccessOutlet($outletId)) {
            return false;
        }

        if ($outletId === null && ! $this->canSeeAllOutlets()) {
            return false;
        }

        $outletId = $outletId === null ? null : self::pick($this->accessibleOutlets(), $outletId);

        $actor->rememberContext($this->tenantId(), $outletId, $outletId === null);

        $this->outletId = $outletId;
        $this->outletResolved = true;

        return true;
    }

    /**
     * The outlet to stamp on a row being created. In the consolidated view
     * there is no single answer unless only one outlet is reachable, so the
     * caller has to have chosen.
     */
    public function outletForWrite(): int|string|null
    {
        $accessible = $this->accessibleOutlets();

        return $this->outletId() ?? (count($accessible) === 1 ? $accessible[0] : null);
    }

    public function forget(): void
    {
        $this->tenants = null;
        $this->tenantResolved = false;
        $this->tenantId = null;
        $this->forgetOutlet();
    }

    /* ----------------------------------------------------------- private */

    private function forgetOutlet(): void
    {
        $this->outlets = null;
        $this->outletResolved = false;
        $this->outletId = null;
    }

    private function resolveTenant(): int|string|null
    {
        $actor = $this->actor();

        if ($actor === null) {
            return null;
        }

        $accessible = $this->accessibleTenants();

        // Ordinary staff are pinned to their own business. A stored choice is
        // irrelevant to them, and honouring it would be a way out.
        if (! $actor->isPlatformAdmin()) {
            return $accessible[0] ?? null;
        }

        $stored = $actor->rememberedTenantKey();

        if ($stored !== null && self::contains($accessible, $stored)) {
            return self::pick($accessible, $stored);
        }

        return count($accessible) === 1 ? $accessible[0] : null;
    }

    private function resolveOutlet(): int|string|null
    {
        $actor = $this->actor();

        if ($actor === null) {
            return null;
        }

        $accessible = $this->accessibleOutlets();

        if ($accessible === []) {
            return null;
        }

        if ($actor->prefersAllOutlets() && count($accessible) > 1) {
            return null;
        }

        foreach ([$actor->rememberedOutletKey(), $actor->defaultOutletKey()] as $candidate) {
            if ($candidate !== null && self::contains($accessible, $candidate)) {
                return self::pick($accessible, $candidate);
            }
        }

        return $accessible[0];
    }

    /**
     * A single-business install where the person has no business set.
     *
     * @param  list<int|string>  $all
     */
    private function soleTenant(array $all): int|string|null
    {
        return count($all) === 1 ? $all[0] : null;
    }

    /**
     * Keys are compared as strings: an int id from one table and the same id
     * as a string from a request must not be two different outlets.
     *
     * @param  list<int|string>  $keys
     */
    private static function contains(array $keys, int|string $key): bool
    {
        return in_array((string) $key, array_map('strval', $keys), true);
    }

    /**
     * The key as the directory spells it.
     *
     * @param  list<int|string>  $keys
     */
    private static function pick(array $keys, int|string $key): int|string
    {
        foreach ($keys as $candidate) {
            if ((string) $candidate === (string) $key) {
                return $candidate;
            }
        }

        return $key;
    }
}
