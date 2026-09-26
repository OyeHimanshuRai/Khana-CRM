<?php

namespace App\Support;

/**
 * The role presets each product ships, and what each one is for.
 *
 * Presets work on day one: each carries the rights its job needs, so an
 * owner picks "Cashier" and the cashier can settle a table. The rights
 * themselves live in the product; Accounts only records which role a person
 * holds. A product's own custom roles (cloned from a preset there) are added
 * to these lists by App\Services\RoleCatalog.
 */
final class Roles
{
    public const OWNER = 'owner';

    /**
     * @var array<string, array<string, array{name: string, can: string}>>
     */
    public const PRESETS = [
        'crm' => [
            'owner' => ['name' => 'Owner', 'can' => 'Everything in CRM, including settings and roles'],
            'manager' => ['name' => 'Manager', 'can' => 'Everything except roles, write-offs and deleting records'],
            'cashier' => ['name' => 'Cashier', 'can' => 'POS, table bills, payments, day close, customers'],
            'captain' => ['name' => 'Captain / Waiter', 'can' => 'Tables, order pad, kitchen status, customers'],
            'kitchen' => ['name' => 'Kitchen Staff', 'can' => 'Kitchen board, KOT printing, sold-out toggles'],
            'auditor' => ['name' => 'Auditor', 'can' => 'Read and export only'],
        ],
        'erp' => [
            'owner' => ['name' => 'Owner', 'can' => 'Everything in ERP, including settings and roles'],
            'store_manager' => ['name' => 'Store Manager', 'can' => 'Stock, counts, transfers, wastage, recipes, purchase orders'],
            'storekeeper' => ['name' => 'Storekeeper', 'can' => 'Goods receipts, issues to kitchen, counts, wastage'],
            'accountant' => ['name' => 'Accountant', 'can' => 'Supplier bills, payments, expenses, input tax reports'],
            'auditor' => ['name' => 'Auditor', 'can' => 'Read and export only'],
        ],
    ];

    public const PRODUCT_NAMES = ['crm' => 'CRM', 'erp' => 'ERP'];

    /**
     * @return array<string, array{name: string, can: string}>
     */
    public static function presets(string $product): array
    {
        return self::PRESETS[$product] ?? [];
    }
}
