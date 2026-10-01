<?php

namespace App\Support;

use App\Models\Role;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * ERP's roles and what each may do.
 *
 * Two kinds. The presets are the ones Khana Accounts offers for ERP on day
 * one (its App\Support\Roles), the same for every business, and are code. A
 * business can also make its own, copied from a preset and changed - those
 * are rows (App\Models\Role), and belong to the business that made them.
 *
 * Either way Accounts decides who holds which and ERP decides what each one
 * opens: a person's role arrives at sign-in as a key, and the key is looked
 * up here.
 *
 * Abilities are named for the job, not the table: `receive` is taking goods
 * in at the back door, whatever rows that writes.
 */
final class Roles
{
    public const ABILITIES = [
        'stock.view' => 'See stock, batches and movements',
        'stock.manage' => 'Add and edit stock items and stores',
        'receive' => 'Receive goods, and stock sent by another outlet',
        'issue' => 'Issue stock to the kitchen and move it between stores',
        'transfer' => 'Send stock to another outlet',
        'count' => 'Count stock and post the difference',
        'wastage' => 'Log wastage',
        'recipes.view' => 'See recipes and dish cost',
        'recipes.manage' => 'Write recipes and make prepared items',
        'purchasing.view' => 'See suppliers, orders and deliveries',
        'purchasing.manage' => 'Raise purchase orders and add suppliers',
        'bills.view' => 'See supplier bills and payments',
        'bills.manage' => 'Record supplier bills, payments and expenses',
        'sales.view' => 'See the sales ERP has heard about',
        'sales.import' => 'Import sales from other billing software',
        'reports.view' => 'Open reports and download them',
        'settings.manage' => 'Change how stock behaves at this outlet',
        'roles.manage' => 'Make and change roles',
    ];

    /** The abilities, as the role form lays them out. */
    public const GROUPS = [
        'Store room' => ['stock.view', 'stock.manage', 'receive', 'issue', 'transfer', 'count', 'wastage'],
        'Recipes' => ['recipes.view', 'recipes.manage'],
        'Buying' => ['purchasing.view', 'purchasing.manage', 'bills.view', 'bills.manage'],
        'Sales and reports' => ['sales.view', 'sales.import', 'reports.view'],
        'Setup' => ['settings.manage'],
    ];

    /**
     * Kept for the owner. A role that could hand out abilities could hand
     * itself all of them, so making roles is not something a made role does.
     */
    public const OWNER_ONLY = ['roles.manage'];

    /**
     * What an ability is no use without: the screen its own work lands on.
     * Somebody who can receive goods is shown the delivery they just took
     * in, so `receive` brings `purchasing.view` with it. Added when a role
     * is saved, so no made role can do a job and then be refused its result.
     */
    public const NEEDS = [
        'stock.manage' => ['stock.view'],
        'receive' => ['stock.view', 'purchasing.view'],
        'issue' => ['stock.view'],
        'transfer' => ['stock.view'],
        'count' => ['stock.view'],
        'wastage' => ['stock.view'],
        'recipes.manage' => ['recipes.view', 'stock.view'],
        'purchasing.manage' => ['purchasing.view'],
        'bills.manage' => ['bills.view'],
        'sales.import' => ['sales.view'],
    ];

    public const PRESETS = [
        'owner' => [
            'name' => 'Owner',
            'can' => ['*'],
        ],
        'store_manager' => [
            'name' => 'Store Manager',
            'can' => [
                'stock.view', 'stock.manage', 'receive', 'issue', 'transfer', 'count', 'wastage',
                'recipes.view', 'recipes.manage', 'purchasing.view', 'purchasing.manage',
                'bills.view', 'sales.view', 'sales.import', 'reports.view',
            ],
        ],
        'storekeeper' => [
            'name' => 'Storekeeper',
            'can' => ['stock.view', 'receive', 'issue', 'count', 'wastage', 'purchasing.view'],
        ],
        'accountant' => [
            'name' => 'Accountant',
            'can' => ['stock.view', 'purchasing.view', 'bills.view', 'bills.manage', 'sales.view', 'reports.view'],
        ],
        'auditor' => [
            'name' => 'Auditor',
            'can' => ['stock.view', 'recipes.view', 'purchasing.view', 'bills.view', 'sales.view', 'reports.view'],
        ],
    ];

    /** @var array<string, Collection<string, Role>> the made roles of each business looked up so far, by key */
    private static array $made = [];

    public static function exists(?string $key, ?string $tenantId = null): bool
    {
        return $key !== null && (isset(self::PRESETS[$key]) || self::made($tenantId)->has($key));
    }

    public static function isPreset(?string $key): bool
    {
        return $key !== null && isset(self::PRESETS[$key]);
    }

    public static function name(?string $key, ?string $tenantId = null): string
    {
        return self::PRESETS[$key]['name'] ?? self::made($tenantId)->get((string) $key)?->name ?? 'No role';
    }

    public static function allows(?string $key, string $ability, ?string $tenantId = null): bool
    {
        $can = self::abilities($key, $tenantId);

        return in_array('*', $can, true) || in_array($ability, $can, true);
    }

    /**
     * The abilities a role carries: `['*']` for the owner, a list otherwise,
     * and nothing for a key nobody has set up.
     *
     * A made role is read through grantable() as well as written through
     * it: whatever its row holds, it opens only abilities ERP knows and
     * never the owner's own. "Everything" is a preset's to give.
     *
     * @return list<string>
     */
    public static function abilities(?string $key, ?string $tenantId = null): array
    {
        if (self::isPreset($key)) {
            return self::PRESETS[$key]['can'];
        }

        $made = self::made($tenantId)->get((string) $key);

        return $made === null ? [] : self::grantable((array) $made->abilities);
    }

    /**
     * What a made role may be given, from what was ticked: only abilities
     * ERP knows, never the owner's own, and each with what it needs.
     *
     * @param  array<int, mixed>  $ticked
     * @return list<string> in the order of ABILITIES
     */
    public static function grantable(array $ticked): array
    {
        $given = array_fill_keys(array_filter($ticked, 'is_string'), true);

        foreach (self::NEEDS as $ability => $needs) {
            if (isset($given[$ability])) {
                $given += array_fill_keys($needs, true);
            }
        }

        return array_values(array_filter(
            array_keys(self::ABILITIES),
            fn (string $ability) => isset($given[$ability]) && ! in_array($ability, self::OWNER_ONLY, true),
        ));
    }

    /**
     * A key for a new role, from its name: "Head Chef" is `head_chef`. A
     * contract `code` (lower case, digits, underscores, a letter first, 40
     * at most), not a preset's and not one this business already uses.
     */
    public static function keyFor(string $name, string $tenantId): string
    {
        $base = Str::of($name)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();

        if ($base === '' || preg_match('/^[a-z]/', $base) !== 1) {
            $base = 'role_'.$base;
        }

        $base = rtrim(substr($base, 0, 36), '_');
        $key = $base;

        for ($n = 2; self::exists($key, $tenantId); $n++) {
            $key = $base.'_'.$n;
        }

        return $key;
    }

    /**
     * The roles as Accounts' team screen lists them (contracts/api/erp.yaml,
     * the same shape CRM answers with): the presets, then whatever this
     * business has made.
     *
     * @return list<array{key: string, name: string, preset: bool}>
     */
    public static function catalogue(?string $tenantId = null): array
    {
        $roles = [];

        foreach (self::PRESETS as $key => $role) {
            $roles[] = ['key' => $key, 'name' => $role['name'], 'preset' => true];
        }

        foreach (self::made($tenantId) as $role) {
            $roles[] = ['key' => $role->key, 'name' => $role->name, 'preset' => false];
        }

        return $roles;
    }

    /**
     * The roles a business has made, by key. Looked up once and kept: every
     * `can()` on a page asks.
     *
     * @return Collection<string, Role>
     */
    public static function made(?string $tenantId): Collection
    {
        if ($tenantId === null) {
            return collect();
        }

        return self::$made[$tenantId] ??= Role::forTenant($tenantId)->orderBy('name')->get()->keyBy('key');
    }

    /** A role was saved or deleted, or a test moved to another database. */
    public static function forget(): void
    {
        self::$made = [];
    }
}
