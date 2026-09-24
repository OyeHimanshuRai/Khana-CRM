<?php

/*
|--------------------------------------------------------------------------
| Admin sidebar menu
|--------------------------------------------------------------------------
|
| Rendered by resources/views/admin/partials/sidebar.blade.php.
|
| Group keys:
|   heading   section label (hidden when the sidebar is collapsed)
|   items     entries in the section
|
| Item keys:
|   label     required
|   icon      name from resources/views/components/icon.blade.php
|   route     named route; omit for a not-yet-built module, which then
|             renders as a placeholder instead of a dead link
|   params    route parameters, for entries that point at the same route
|             with a different segment (the reports do this)
|   active    route-name pattern(s) used to highlight the entry; defaults
|             to the item's own route name
|   badge     small pill on the right
|   can       permission required to see the entry. A parent is hidden
|             automatically once all of its children are hidden, and an
|             empty section heading is dropped with it.
|   children  submenu items (same keys, no icon needed)
|
| Permission names come from config/permissions.php.
|
| Entries without a `route` are modules still to be built. They are listed
| on purpose: the shape of the finished system is easier to review here than
| in a plan, and the sidebar renders them as inert placeholders rather than
| dead links.
|
*/

return [

    [
        'heading' => 'Main',
        'items' => [
            [
                'label' => 'Dashboard',
                'icon' => 'grid',
                'route' => 'admin.dashboard',
                'can' => 'dashboard.overview.view',
            ],
        ],
    ],

    /*
    | The room. Above the counter because on a busy evening the floor plan is
    | the screen the front of house lives in - the till is opened when a table
    | asks for the bill, the plan is looked at between every cover.
    */
    [
        'heading' => 'Floor',
        'items' => [
            [
                'label' => 'Floor Plan',
                'icon' => 'grid',
                'route' => 'admin.tables.plan',
                'active' => 'admin.tables.plan',
                'can' => 'dining.tables.view',
            ],
            [
                'label' => 'Tables',
                'icon' => 'list',
                'route' => 'admin.tables.index',
                // The plan has its own entry above and must not light this
                // one up as well, so the pattern is spelled out rather than
                // wildcarded.
                'active' => ['admin.tables.index', 'admin.tables.show', 'admin.tables.edit', 'admin.tables.create'],
                'can' => 'dining.tables.view',
            ],
            [
                'label' => 'Dining Areas',
                'icon' => 'building',
                'route' => 'admin.floors.index',
                'active' => 'admin.floors.*',
                'can' => 'dining.floors.view',
            ],
            [
                'label' => 'Table QR Codes',
                'icon' => 'scan',
                'route' => 'admin.qr.index',
                'active' => 'admin.qr.*',
                'can' => 'dining.qr.view',
            ],
            /*
             | Bookings (§7). With the floor rather than with orders, because
             | a reservation is a table promised for a time - it is a fact
             | about the room, not about a bill.
             */
            [
                'label' => 'Reservations',
                'icon' => 'calendar',
                'route' => 'admin.reservations.index',
                'active' => 'admin.reservations.*',
                'can' => 'dining.reservations.view',
            ],
        ],
    ],

    /*
    | The kitchen. Between the floor and the counter because that is the order
    | of the evening: a table orders, the kitchen cooks, the till is opened at
    | the end. The display is first in its own section for the same reason the
    | floor plan is first in its - it is the screen somebody lives in.
    */
    [
        'heading' => 'Kitchen',
        'items' => [
            [
                'label' => 'Kitchen Display',
                'icon' => 'zap',
                'route' => 'admin.kitchen.index',
                'active' => 'admin.kitchen.*',
                'can' => 'kitchen.tickets.view',
            ],
            [
                'label' => 'Stations',
                'icon' => 'tool',
                'route' => 'admin.kitchen-stations.index',
                'active' => 'admin.kitchen-stations.*',
                'can' => 'kitchen.stations.view',
            ],
        ],
    ],

    /*
    | The counter. Below the floor because for a restaurant the till is
    | opened when a table asks for the bill rather than lived in all day.
    */
    [
        'heading' => 'Counter',
        'items' => [
            /*
            | Above the counter's own screen, because in a restaurant the till
            | is opened for a table far more often than for somebody standing
            | at it.
            */
            [
                'label' => 'Table Bills',
                'icon' => 'wallet',
                'route' => 'admin.table-bills.index',
                'active' => 'admin.table-bills.*',
                'can' => 'pos.tables.view',
            ],
            [
                'label' => 'Held Sales',
                'icon' => 'clock',
                'route' => 'admin.pos.parked',
                'active' => 'admin.pos.parked',
                'can' => 'pos.terminal.create',
            ],
            [
                'label' => 'POS Billing',
                'icon' => 'cart',
                'route' => 'admin.pos.terminal',
                'active' => 'admin.pos.terminal',
                'can' => 'pos.terminal.view',
            ],
            [
                'label' => 'Day Close',
                'icon' => 'wallet',
                'route' => 'admin.registers.index',
                'active' => 'admin.registers.*',
                'can' => 'pos.registers.view',
            ],
            [
                'label' => 'Barcode Labels',
                'icon' => 'tag',
                'route' => 'admin.labels.index',
                'active' => 'admin.labels.*',
                'can' => 'pos.labels.view',
            ],
        ],
    ],

    [
        'heading' => 'Operations',
        'items' => [
            [
                'label' => 'Menu & Stock',
                'icon' => 'package',
                'children' => [
                    [
                        'label' => 'Menu Items',
                        'route' => 'admin.products.index',
                        'active' => 'admin.products.*',
                        'can' => 'inventory.products.view',
                    ],
                    [
                        'label' => 'Categories',
                        'route' => 'admin.categories.index',
                        'active' => 'admin.categories.*',
                        'can' => 'inventory.categories.view',
                    ],
                    [
                        'label' => 'Brands',
                        'route' => 'admin.brands.index',
                        'active' => 'admin.brands.*',
                        'can' => 'inventory.brands.view',
                    ],
                    [
                        'label' => 'Price Lists',
                        'route' => 'admin.price-lists.index',
                        'active' => 'admin.price-lists.*',
                        'can' => 'inventory.price_lists.view',
                    ],
                    [
                        'label' => 'Add-ons & Modifiers',
                        'route' => 'admin.modifiers.index',
                        'active' => 'admin.modifiers.*',
                        'can' => 'inventory.modifiers.view',
                    ],
                    [
                        'label' => 'Recipes / BOM',
                        'route' => 'admin.recipes.index',
                        'active' => 'admin.recipes.*',
                        'can' => 'inventory.recipes.view',
                    ],
                    [
                        'label' => 'Wastage',
                        'route' => 'admin.wastage.index',
                        'active' => 'admin.wastage.*',
                        'can' => 'inventory.wastage.view',
                    ],
                    [
                        'label' => 'Units',
                        'route' => 'admin.units.index',
                        'active' => 'admin.units.*',
                        'can' => 'inventory.units.view',
                    ],
                    [
                        'label' => 'Stock on Hand',
                        'route' => 'admin.stock.index',
                        'active' => 'admin.stock.*',
                        'can' => 'inventory.stock.view',
                    ],
                    [
                        'label' => 'Batches & Expiry',
                        'route' => 'admin.batches.index',
                        'active' => 'admin.batches.*',
                        'can' => 'inventory.batches.view',
                    ],
                    [
                        'label' => 'Stock Adjustments',
                        'route' => 'admin.stock-adjustments.index',
                        'active' => 'admin.stock-adjustments.*',
                        'can' => 'inventory.adjustments.view',
                    ],
                    [
                        'label' => 'Stock Transfers',
                        'route' => 'admin.stock-transfers.index',
                        'active' => 'admin.stock-transfers.*',
                        'can' => 'inventory.transfers.view',
                    ],
                    [
                        'label' => 'Warehouses',
                        'route' => 'admin.warehouses.index',
                        'active' => 'admin.warehouses.*',
                        'can' => 'inventory.warehouses.view',
                    ],
                ],
            ],
            [
                'label' => 'Sales',
                'icon' => 'cart',
                'children' => [
                    [
                        'label' => 'Invoices',
                        'route' => 'admin.invoices.index',
                        'active' => ['admin.invoices.*', 'admin.pos.manual'],
                        'can' => 'sales.invoices.view',
                    ],
                    [
                        'label' => 'Sales Returns',
                        'route' => 'admin.sales-returns.index',
                        'active' => 'admin.sales-returns.*',
                        'can' => 'sales.returns.view',
                    ],
                    [
                        // Above the history, because "what is running late" is
                        // asked forty times an evening and "what did we sell
                        // last Tuesday" is asked once a month.
                        'label' => 'Live Orders',
                        'route' => 'admin.live-orders.index',
                        'active' => 'admin.live-orders.*',
                        'can' => 'sales.orders.view',
                    ],
                    [
                        // Named for what it holds now: every channel, not only
                        // the storefront's. See OnlineOrderController.
                        'label' => 'Orders',
                        'route' => 'admin.orders.index',
                        'active' => 'admin.orders.*',
                        'can' => 'sales.orders.view',
                    ],
                    [
                        'label' => 'Coupons & Offers',
                        'route' => 'admin.coupons.index',
                        'active' => 'admin.coupons.*',
                        'can' => 'sales.coupons.view',
                    ],
                ],
            ],
            [
                'label' => 'Purchasing',
                'icon' => 'truck',
                'children' => [
                    [
                        'label' => 'Purchase Orders',
                        'route' => 'admin.purchase-orders.index',
                        'active' => 'admin.purchase-orders.*',
                        'can' => 'purchasing.purchase_orders.view',
                    ],
                    [
                        'label' => 'Goods Receipts',
                        'route' => 'admin.receipts.index',
                        'active' => 'admin.receipts.*',
                        'can' => 'purchasing.receipts.view',
                    ],
                    [
                        'label' => 'Purchase Invoices',
                        'route' => 'admin.purchase-invoices.index',
                        'active' => 'admin.purchase-invoices.*',
                        'can' => 'purchasing.bills.view',
                    ],
                    [
                        'label' => 'Purchase Returns',
                        'route' => 'admin.purchase-returns.index',
                        'active' => 'admin.purchase-returns.*',
                        'can' => 'purchasing.returns.view',
                    ],
                    [
                        'label' => 'Suppliers',
                        'route' => 'admin.suppliers.index',
                        'active' => 'admin.suppliers.*',
                        'can' => 'purchasing.suppliers.view',
                    ],
                ],
            ],
            [
                'label' => 'Finance',
                'icon' => 'wallet',
                'children' => [
                    [
                        'label' => 'Payments',
                        'route' => 'admin.payments.index',
                        'active' => 'admin.payments.*',
                        'can' => 'finance.payments.view',
                    ],
                    /*
                     | The gateway's side (§4, §11). Separate from the
                     | customer ledger above: one is what a regular owes, the
                     | other is what guests paid from their phones.
                     */
                    [
                        'label' => 'Online Payments',
                        'route' => 'admin.online-payments.index',
                        'active' => 'admin.online-payments.index',
                        'can' => 'finance.online_payments.view',
                    ],
                    [
                        'label' => 'Refunds',
                        'route' => 'admin.online-payments.refunds',
                        'active' => 'admin.online-payments.refunds',
                        'can' => 'finance.online_payments.view',
                    ],
                    [
                        'label' => 'Settlement',
                        'route' => 'admin.online-payments.settlements',
                        'active' => 'admin.online-payments.settlements',
                        'can' => 'finance.online_payments.view',
                    ],
                    [
                        'label' => 'Expenses',
                        'route' => 'admin.expenses.index',
                        'active' => 'admin.expenses.*',
                        'can' => 'finance.expenses.view',
                    ],
                    [
                        'label' => 'Tax / GST Setup',
                        'route' => 'admin.taxes.index',
                        'active' => 'admin.taxes.*',
                        'can' => 'finance.taxes.view',
                    ],
                    ['label' => 'Chart of Accounts', 'can' => 'finance.accounts.view'],
                ],
            ],
        ],
    ],

    [
        'heading' => 'Customers',
        'items' => [
            [
                'label' => 'CRM',
                'icon' => 'users',
                'children' => [
                    [
                        'label' => 'Customer Master',
                        'route' => 'admin.customers.index',
                        'active' => 'admin.customers.*',
                        'can' => 'crm.customers.view',
                    ],
                    [
                        'label' => 'Customer Ledger',
                        'route' => 'admin.ledger.index',
                        'active' => 'admin.ledger.*',
                        'can' => 'crm.ledger.view',
                    ],
                    [
                        'label' => 'Credit & Dues',
                        'route' => 'admin.dues.index',
                        'active' => 'admin.dues.*',
                        'can' => 'crm.dues.view',
                    ],
                    [
                        'label' => 'Loyalty Points',
                        'route' => 'admin.loyalty.index',
                        'active' => 'admin.loyalty.*',
                        'can' => 'crm.loyalty.view',
                    ],
                    [
                        'label' => 'Campaigns',
                        'route' => 'admin.campaigns.index',
                        'active' => 'admin.campaigns.*',
                        'can' => 'crm.campaigns.view',
                    ],
                    [
                        'label' => 'Guest Feedback',
                        'route' => 'admin.feedback.index',
                        'active' => 'admin.feedback.*',
                        'can' => 'crm.feedback.view',
                    ],
                    [
                        'label' => 'Payment Reminders',
                        'route' => 'admin.reminders.index',
                        'active' => 'admin.reminders.*',
                        'can' => 'crm.reminders.view',
                    ],
                ],
            ],
        ],
    ],

    [
        'heading' => 'Content',
        'items' => [
            [
                'label' => 'Sliders',
                'icon' => 'package',
                'route' => 'admin.sliders.index',
                'active' => 'admin.sliders.*',
                'can' => 'content.sliders.view',
            ],
            [
                'label' => 'Services',
                'icon' => 'tool',
                'route' => 'admin.services.index',
                'active' => 'admin.services.*',
                'can' => 'content.services.view',
            ],
            [
                'label' => 'Collections',
                'icon' => 'grid',
                'route' => 'admin.collections.index',
                'active' => 'admin.collections.*',
                'can' => 'content.collections.view',
            ],
            [
                'label' => 'Events',
                'icon' => 'calendar',
                'route' => 'admin.events.index',
                'active' => 'admin.events.*',
                'can' => 'content.events.view',
            ],
            [
                'label' => 'Blog',
                'icon' => 'file',
                'route' => 'admin.blogs.index',
                'active' => 'admin.blogs.*',
                'can' => 'content.blogs.view',
            ],
            [
                'label' => 'Instagram',
                'icon' => 'heart',
                'children' => [
                    [
                        'label' => 'Reels',
                        'route' => 'admin.reels.index',
                        'active' => 'admin.reels.*',
                        'can' => 'content.reels.view',
                    ],
                    [
                        'label' => 'Posts',
                        'route' => 'admin.instagram.index',
                        'active' => 'admin.instagram.*',
                        'can' => 'content.instagram.view',
                    ],
                ],
            ],
            /*
             | The landing page (§19).
             |
             | Grouped under one parent rather than six siblings, because that
             | is how somebody looks for them: "change something on the
             | website" is one errand, and six top-level entries would bury the
             | rest of the Content section under it.
             |
             | Demo Requests sits in the same group and is the odd one - it is
             | an inbox, not copy - but it is where the website sends people,
             | so it is where somebody will look for what the website
             | collected.
             */
            [
                'label' => 'Landing Page',
                'icon' => 'globe',
                'children' => [
                    [
                        'label' => 'Trust Numbers',
                        'route' => 'admin.landing-stats.index',
                        'active' => 'admin.landing-stats.*',
                        'can' => 'content.stats.view',
                    ],
                    [
                        'label' => 'Testimonials',
                        'route' => 'admin.testimonials.index',
                        'active' => 'admin.testimonials.*',
                        'can' => 'content.testimonials.view',
                    ],
                    [
                        'label' => 'Outlet Types',
                        'route' => 'admin.outlet-types.index',
                        'active' => 'admin.outlet-types.*',
                        'can' => 'content.outlet_types.view',
                    ],
                    [
                        'label' => 'Integrations',
                        'route' => 'admin.integrations.index',
                        'active' => 'admin.integrations.*',
                        'can' => 'content.integrations.view',
                    ],
                    [
                        'label' => 'Screenshots',
                        'route' => 'admin.showcases.index',
                        'active' => 'admin.showcases.*',
                        'can' => 'content.showcases.view',
                    ],
                    [
                        'label' => 'Demo Requests',
                        'route' => 'admin.demo-requests.index',
                        'active' => 'admin.demo-requests.*',
                        'can' => 'content.demo_requests.view',
                    ],
                ],
            ],
            [
                'label' => 'FAQs',
                'icon' => 'help',
                'route' => 'admin.faqs.index',
                'active' => 'admin.faqs.*',
                'can' => 'content.faqs.view',
            ],
        ],
    ],

    /* Marketing. */
    [
        'heading' => 'Marketing',
        'items' => [
            [
                'label' => 'Email Templates',
                'icon' => 'file',
                'route' => 'admin.email.templates.index',
                'active' => 'admin.email.templates.*',
                'can' => 'email.templates.view',
            ],
            [
                'label' => 'Email Logs',
                'icon' => 'list',
                'route' => 'admin.email.logs.index',
                'active' => 'admin.email.logs.*',
                'can' => 'email.logs.view',
            ],
        ],
    ],

    [
        'heading' => 'Insights',
        'items' => [
            [
                'label' => 'Reports',
                'icon' => 'chart',
                'active' => 'admin.reports.*',
                'children' => [
                    [
                        'label' => 'All reports',
                        'route' => 'admin.reports.index',
                        'active' => 'admin.reports.index',
                        'can' => 'reports.sales_report.view',
                    ],
                    /*
                     | Every report below is the same route with a different
                     | segment, so they carry `params` rather than needing a
                     | controller action each.
                     */
                    [
                        'label' => 'Sales',
                        'route' => 'admin.reports.show',
                        'params' => ['report' => 'sales'],
                        'can' => 'reports.sales_report.view',
                    ],
                    [
                        'label' => 'Profit',
                        'route' => 'admin.reports.show',
                        'params' => ['report' => 'profit'],
                        'can' => 'reports.profit_report.view',
                    ],
                    [
                        'label' => 'Product Sales',
                        'route' => 'admin.reports.show',
                        'params' => ['report' => 'products'],
                        'can' => 'reports.sales_report.view',
                    ],
                    [
                        'label' => 'Purchases',
                        'route' => 'admin.reports.show',
                        'params' => ['report' => 'purchases'],
                        'can' => 'reports.purchase_report.view',
                    ],
                    [
                        'label' => 'Stock & Valuation',
                        'route' => 'admin.reports.show',
                        'params' => ['report' => 'stock'],
                        'can' => 'reports.stock_report.view',
                    ],
                    [
                        'label' => 'Low Stock',
                        'route' => 'admin.reports.show',
                        'params' => ['report' => 'low-stock'],
                        'can' => 'reports.expiry_report.view',
                    ],
                    [
                        'label' => 'Expiry',
                        'route' => 'admin.reports.show',
                        'params' => ['report' => 'expiry'],
                        'can' => 'reports.expiry_report.view',
                    ],
                    [
                        'label' => 'Outstanding & Dues',
                        'route' => 'admin.reports.show',
                        'params' => ['report' => 'outstanding'],
                        'can' => 'reports.dues_report.view',
                    ],
                    [
                        'label' => 'Payment Methods',
                        'route' => 'admin.reports.show',
                        'params' => ['report' => 'payments'],
                        'can' => 'reports.payment_report.view',
                    ],
                    [
                        'label' => 'Tax / GST',
                        'route' => 'admin.reports.show',
                        'params' => ['report' => 'tax'],
                        'can' => 'reports.tax_report.view',
                    ],
                    [
                        'label' => 'Stock Transfers',
                        'route' => 'admin.reports.show',
                        'params' => ['report' => 'transfers'],
                        'can' => 'reports.stock_report.view',
                    ],
                    [
                        'label' => 'Supplier Payables',
                        'route' => 'admin.reports.show',
                        'params' => ['report' => 'payables'],
                        'can' => 'reports.supplier_report.view',
                    ],
                    [
                        'label' => 'Day Closing',
                        'route' => 'admin.reports.show',
                        'params' => ['report' => 'day-closing'],
                        'can' => 'reports.payment_report.view',
                    ],
                    [
                        'label' => 'Returns & Refunds',
                        'route' => 'admin.reports.show',
                        'params' => ['report' => 'returns'],
                        'can' => 'reports.returns_report.view',
                    ],
                    [
                        'label' => 'Financial Summary',
                        'route' => 'admin.reports.show',
                        'params' => ['report' => 'finance'],
                        'can' => 'reports.finance_report.view',
                    ],
                ],
            ],
        ],
    ],

    [
        'heading' => 'System',
        'items' => [
            /*
             | Companies above Shops, in that order, because that is the
             | shape of the data: a company owns branches. Most installs run
             | one company and the entry is invisible to everyone who is not
             | administering the platform.
             */
            [
                'label' => 'Companies',
                'icon' => 'grid',
                'route' => 'admin.tenants.index',
                'active' => 'admin.tenants.*',
                'can' => 'settings.tenants.view',
            ],
            [
                'label' => 'Shops',
                'icon' => 'building',
                'route' => 'admin.shops.index',
                'active' => 'admin.shops.*',
                'can' => 'settings.shops.view',
            ],
            /*
             | Subscriptions (§4, §21).
             |
             | One entry, two destinations, decided by what the reader holds:
             |
             |   .edit   the platform's list of every business and what it
             |           pays. Super Admin.
             |   .view   their own account - which plan, what is left of it,
             |           when it renews. A Tenant Owner.
             |
             | Two menu entries pointing at two halves of the same subject
             | would leave a restaurant owner reading "Subscriptions" and
             | wondering whose. The label follows the sitemap's wording.
             */
            [
                'label' => 'Subscription',
                'icon' => 'wallet',
                'route' => 'admin.subscription.mine',
                'active' => 'admin.subscription.*',
                'can' => 'settings.subscriptions.view',
                'unless_can' => 'settings.subscriptions.edit',
            ],
            /*
             | Billing (§11, §21).
             |
             | Beside Subscription rather than folded into it, because the two
             | answer different questions: that screen says what this account
             | is on, this one takes the money for it. Shown to the same
             | reader - the owner - and to a Super Admin, who needs to be
             | able to open what their customer is looking at.
             */
            [
                'label' => 'Billing',
                'icon' => 'wallet',
                'route' => 'admin.billing.show',
                'active' => 'admin.billing.*',
                'can' => 'settings.subscriptions.view',
            ],
            [
                'label' => 'Subscriptions',
                'icon' => 'wallet',
                'route' => 'admin.subscriptions.index',
                'active' => 'admin.subscriptions.*',
                'can' => 'settings.subscriptions.edit',
            ],
            [
                'label' => 'Plans',
                'icon' => 'tag',
                'route' => 'admin.plans.index',
                'active' => 'admin.plans.*',
                'can' => 'settings.plans.view',
            ],
            /*
             | The support desk (§2).
             |
             | One entry, not two, although it serves both ends of the desk -
             | the restaurant raising a ticket and the platform staff working
             | the queue. `unless_can` is deliberately absent here: unlike
             | Subscription above, the two audiences read the *same* screen,
             | which simply shows more to whoever holds
             | `support.tickets.manage`. Splitting the label would imply two
             | places to look, and a ticket going missing between them.
             */
            [
                'label' => 'Support',
                'icon' => 'help',
                'route' => 'admin.support.index',
                'active' => 'admin.support.*',
                'can' => 'support.tickets.view',
            ],
            /*
             | Printers and devices (§4).
             |
             | A parent with two children rather than one entry, because the
             | two are read for different reasons by different people: the
             | list is configured once, and the log is opened during service
             | when a ticket did not arrive.
             */
            [
                'label' => 'Printers',
                'icon' => 'file',
                'children' => [
                    [
                        'label' => 'Printers & Devices',
                        'route' => 'admin.printers.index',
                        'active' => 'admin.printers.index',
                        'can' => 'settings.printers.view',
                    ],
                    [
                        'label' => 'API Tokens',
                        'route' => 'admin.api-tokens.index',
                        'active' => 'admin.api-tokens.*',
                        'can' => 'settings.api_tokens.view',
                    ],
                    [
                        'label' => 'Print Log',
                        'route' => 'admin.printers.jobs',
                        'active' => 'admin.printers.jobs',
                        'can' => 'settings.printers.view',
                    ],
                ],
            ],
            [
                'label' => 'Settings',
                'icon' => 'settings',
                'children' => [
                    [
                        'label' => 'Users',
                        'route' => 'admin.users.index',
                        'active' => 'admin.users.*',
                        'can' => 'settings.users.view',
                    ],
                    [
                        'label' => 'Roles & Permissions',
                        'route' => 'admin.roles.index',
                        'active' => 'admin.roles.*',
                        'can' => 'settings.roles.view',
                    ],
                    [
                        'label' => 'Activity Logs',
                        'route' => 'admin.activity.index',
                        'active' => 'admin.activity.*',
                        'can' => 'settings.activity_logs.view',
                    ],
                    /*
                     | System health (§17).
                     |
                     | Next to the activity log because the two answer the
                     | same question from opposite ends: the log is what
                     | people did, this is what the machine did while nobody
                     | was watching. Platform staff only - see the permission.
                     */
                    [
                        'label' => 'System Health',
                        'route' => 'admin.health.index',
                        'active' => 'admin.health.*',
                        'can' => 'settings.health.view',
                    ],
                    [
                        'label' => 'General',
                        'route' => 'admin.settings.company',
                        'active' => 'admin.settings.*',
                        'can' => 'settings.general.view',
                    ],
                ],
            ],
        ],
    ],

];
