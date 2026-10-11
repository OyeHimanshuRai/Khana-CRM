<?php

use App\Support\Menu\WhenAnyTab;
use App\Support\Menu\WhenSeveralOutlets;

/*
|--------------------------------------------------------------------------
| ERP's navigation
|--------------------------------------------------------------------------
|
| Drawn by the kit's sidebar (khana::partials.sidebar) and, for a hub's own
| screens, by resources/views/partials/hub-tabs.blade.php.
|
| The sidebar is a short list of hubs, in the order a storekeeper's day
| runs: what is on the shelf, what came in, what went to the kitchen, what
| was counted. A hub's screens are tabs across the top of its pages, so any
| job is the hub, then the tab.
|
| A hub links to its first tab, so the first tab of each is the one the
| most roles may open. The hub itself shows as long as the person may open
| any tab of it (WhenAnyTab); a tab they may not open is not drawn.
|
| Tab keys: label, route, active (route-name patterns; defaults to the
| route), can (an ability from App\Support\Roles), params, when (a class
| that answers whether this business has any use for the tab).
|
*/

$hub = function (string $label, string $icon, array $tabs): array {
    $active = [];

    foreach ($tabs as $tab) {
        array_push($active, ...(array) ($tab['active'] ?? $tab['route']));
    }

    return [
        'label' => $label,
        'icon' => $icon,
        'route' => $tabs[0]['route'],
        'params' => $tabs[0]['params'] ?? [],
        'active' => $active,
        'when' => WhenAnyTab::class,
        'tabs' => $tabs,
    ];
};

return [

    [
        'heading' => null,
        'items' => [
            ['label' => 'Home', 'icon' => 'home', 'route' => 'home', 'active' => 'home'],
        ],
    ],

    [
        'heading' => 'Store',
        'items' => [
            $hub('Stock', 'package', [
                ['label' => 'On hand', 'route' => 'stock.index', 'active' => ['stock.index', 'stock.show'], 'can' => 'stock.view'],
                ['label' => 'Items', 'route' => 'items.index', 'active' => 'items.*', 'can' => 'stock.view'],
                ['label' => 'Movements', 'route' => 'movements.index', 'active' => 'movements.*', 'can' => 'stock.view'],
                ['label' => 'Transfers', 'route' => 'transfers.index', 'active' => 'transfers.*', 'can' => 'stock.view', 'when' => WhenSeveralOutlets::class],
                ['label' => 'Stores', 'route' => 'stores.index', 'active' => 'stores.*', 'can' => 'stock.manage'],
            ]),
            $hub('Purchases', 'cart', [
                ['label' => 'Goods received', 'route' => 'receipts.index', 'active' => 'receipts.*', 'can' => 'purchasing.view'],
                ['label' => 'Orders', 'route' => 'orders.index', 'active' => 'orders.*', 'can' => 'purchasing.view'],
                ['label' => 'Suppliers', 'route' => 'suppliers.index', 'active' => 'suppliers.*', 'can' => 'purchasing.view'],
            ]),
            $hub('Kitchen', 'zap', [
                ['label' => 'Issue to kitchen', 'route' => 'issues.index', 'active' => 'issues.*', 'can' => 'issue'],
                ['label' => 'Wastage', 'route' => 'wastage.index', 'active' => 'wastage.*', 'can' => 'wastage'],
                ['label' => 'Production', 'route' => 'production.index', 'active' => 'production.*', 'can' => 'recipes.manage'],
            ]),
            $hub('Counts', 'list', [
                ['label' => 'Stock counts', 'route' => 'counts.index', 'active' => 'counts.*', 'can' => 'count'],
            ]),
        ],
    ],

    [
        'heading' => 'Kitchen office',
        'items' => [
            $hub('Recipes', 'file', [
                ['label' => 'Dishes', 'route' => 'recipes.index', 'active' => 'recipes.*', 'can' => 'recipes.view'],
                ['label' => 'Prepared items', 'route' => 'prepared.index', 'active' => 'prepared.*', 'can' => 'recipes.view'],
            ]),
            $hub('Sales', 'chart', [
                ['label' => 'Sales', 'route' => 'sales.index', 'active' => ['sales.index', 'sales.show'], 'can' => 'sales.view'],
                ['label' => 'Import', 'route' => 'sales.import', 'active' => 'sales.import*', 'can' => 'sales.import'],
            ]),
            $hub('Bills', 'wallet', [
                ['label' => 'Supplier bills', 'route' => 'bills.index', 'active' => 'bills.*', 'can' => 'bills.view'],
                ['label' => 'Payments', 'route' => 'payments.index', 'active' => 'payments.*', 'can' => 'bills.view'],
                ['label' => 'Expenses', 'route' => 'expenses.index', 'active' => 'expenses.*', 'can' => 'bills.view'],
                ['label' => 'Paid-outs', 'route' => 'paid-outs.index', 'active' => 'paid-outs.*', 'can' => 'bills.view'],
            ]),
            $hub('Reports', 'chart', [
                ['label' => 'Food cost', 'route' => 'reports.show', 'params' => ['report' => 'food-cost'], 'can' => 'reports.view'],
                ['label' => 'Variance', 'route' => 'reports.show', 'params' => ['report' => 'variance'], 'can' => 'reports.view'],
                ['label' => 'Stock value', 'route' => 'reports.show', 'params' => ['report' => 'stock-value'], 'can' => 'reports.view'],
                ['label' => 'Expiry', 'route' => 'reports.show', 'params' => ['report' => 'expiry'], 'can' => 'reports.view'],
                ['label' => 'Purchases', 'route' => 'reports.show', 'params' => ['report' => 'purchases'], 'can' => 'reports.view'],
                ['label' => 'Payables', 'route' => 'reports.show', 'params' => ['report' => 'payables'], 'can' => 'reports.view'],
            ]),
        ],
    ],

    [
        'heading' => 'Setup',
        'items' => [
            $hub('Settings', 'settings', [
                ['label' => 'Stock settings', 'route' => 'settings.edit', 'active' => 'settings.*', 'can' => 'settings.manage'],
                ['label' => 'Roles', 'route' => 'roles.index', 'active' => 'roles.*', 'can' => 'roles.manage'],
            ]),
        ],
    ],

];
