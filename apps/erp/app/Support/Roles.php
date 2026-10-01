<?php

namespace App\Support;

/**
 * ERP's roles and what each may do.
 *
 * The keys are the presets Khana Accounts offers for ERP (its
 * App\Support\Roles) - Accounts decides who holds which, ERP decides what
 * each one opens. A person's role arrives at sign-in; nothing here is edited
 * on a screen.
 *
 * Abilities are named for the job, not the table: `receive` is taking goods
 * in at the back door, whatever rows that writes.
 */
final class Roles
{
    public const ABILITIES = [
        'stock.view' => 'See stock, batches and movements',
        'stock.manage' => 'Add and edit stock items and stores',
        'receive' => 'Receive goods',
        'issue' => 'Issue stock to the kitchen and move it between stores',
        'count' => 'Count stock and post the difference',
        'wastage' => 'Log wastage',
        'recipes.view' => 'See recipes and dish cost',
        'recipes.manage' => 'Write recipes and prepared items',
        'purchasing.view' => 'See suppliers and purchase orders',
        'purchasing.manage' => 'Raise purchase orders and add suppliers',
        'bills.view' => 'See supplier bills and payments',
        'bills.manage' => 'Record supplier bills, payments and expenses',
        'sales.view' => 'See the sales ERP has heard about',
        'sales.import' => 'Import sales from other billing software',
        'reports.view' => 'Open reports',
        'settings.manage' => 'Change how stock behaves at this outlet',
    ];

    public const PRESETS = [
        'owner' => [
            'name' => 'Owner',
            'can' => ['*'],
        ],
        'store_manager' => [
            'name' => 'Store Manager',
            'can' => [
                'stock.view', 'stock.manage', 'receive', 'issue', 'count', 'wastage',
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

    public static function exists(?string $key): bool
    {
        return $key !== null && isset(self::PRESETS[$key]);
    }

    public static function name(?string $key): string
    {
        return self::PRESETS[$key]['name'] ?? 'No role';
    }

    public static function allows(?string $key, string $ability): bool
    {
        $can = self::PRESETS[$key]['can'] ?? [];

        return in_array('*', $can, true) || in_array($ability, $can, true);
    }

    /**
     * The roles as Accounts' team screen lists them (contracts/api/erp.yaml,
     * the same shape CRM answers with).
     *
     * @return list<array{key: string, name: string, preset: bool}>
     */
    public static function catalogue(): array
    {
        $roles = [];

        foreach (self::PRESETS as $key => $role) {
            $roles[] = ['key' => $key, 'name' => $role['name'], 'preset' => true];
        }

        return $roles;
    }
}
