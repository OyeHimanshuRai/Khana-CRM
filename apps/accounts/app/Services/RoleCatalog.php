<?php

namespace App\Services;

use App\Models\Tenant;
use App\Support\Roles;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Every role a business can hand its people, product by product: the
 * presets (App\Support\Roles), and after them the roles the business made
 * for itself inside CRM or ERP.
 *
 * A made role lives in the product that made it, so the product is asked
 * (GET /api/v1/roles, contracts/api/crm.yaml and erp.yaml). The team screen
 * must open whether or not a product answers, so asking is never allowed to
 * fail it: a product that is down, slow or not set up simply adds nothing,
 * and the presets are offered alone. What a product did say is kept for a
 * minute, so one visit to the team screen is one question to each.
 */
final class RoleCatalog
{
    private const KEEP_SECONDS = 60;

    private const WAIT_SECONDS = 3;

    public function __construct(private readonly OwnMachineToken $token) {}

    /**
     * @return array<string, array<string, array{name: string, can: string}>> product => key => role
     */
    public function for(Tenant $tenant): array
    {
        $roles = Roles::PRESETS;

        foreach (array_keys($roles) as $product) {
            foreach ($this->madeIn($product, $tenant) as $key => $name) {
                // A preset keeps its own wording whatever the product says.
                $roles[$product][$key] ??= ['name' => $name, 'can' => 'A role you made in '.Roles::PRODUCT_NAMES[$product]];
            }
        }

        return $roles;
    }

    /** May this business give this role in this product? */
    public function offers(Tenant $tenant, string $product, string $key): bool
    {
        return isset($this->for($tenant)[$product][$key]);
    }

    /** A role was just made or renamed in a product: ask again next time. */
    public function forget(Tenant $tenant): void
    {
        foreach (array_keys(Roles::PRESETS) as $product) {
            Cache::forget($this->cacheKey($product, $tenant));
        }
    }

    /**
     * The roles a business made in one product.
     *
     * @return array<string, string> key => name
     */
    private function madeIn(string $product, Tenant $tenant): array
    {
        $kept = Cache::get($this->cacheKey($product, $tenant));

        if (is_array($kept)) {
            return $kept;
        }

        $made = $this->ask($product, $tenant);

        // Only an answer is kept. A product that could not be reached is
        // asked again on the next visit, not written off for a minute.
        if ($made !== null) {
            Cache::put($this->cacheKey($product, $tenant), $made, self::KEEP_SECONDS);
        }

        return $made ?? [];
    }

    /**
     * @return array<string, string>|null null when the product did not answer
     */
    private function ask(string $product, Tenant $tenant): ?array
    {
        $url = rtrim((string) config('khana.urls.'.$product), '/');

        if ($url === '') {
            return null;
        }

        try {
            $response = Http::withToken($this->token->for(['roles:read']))
                ->acceptJson()
                ->timeout(self::WAIT_SECONDS)
                ->get($url.'/api/v1/roles', ['tenant_id' => $tenant->ulid]);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful() || ! is_array($response->json('roles'))) {
            return null;
        }

        $made = [];

        foreach ($response->json('roles') as $role) {
            $key = $role['key'] ?? null;
            $name = $role['name'] ?? null;

            // Only what the contract allows through: a `code` for a key.
            if (($role['preset'] ?? true) === false && is_string($key) && is_string($name) && $name !== ''
                && preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key) === 1) {
                $made[$key] = $name;
            }
        }

        return $made;
    }

    private function cacheKey(string $product, Tenant $tenant): string
    {
        return 'khana:roles:'.$product.':'.$tenant->ulid;
    }
}
