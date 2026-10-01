<?php

/*
|--------------------------------------------------------------------------
| Admin navigation
|--------------------------------------------------------------------------
|
| Read by App\Support\Navigation, drawn by
| resources/views/admin/partials/sidebar.blade.php (the hubs) and
| resources/views/admin/partials/hub-tabs.blade.php (a hub's screens).
|
| The sidebar is a short list of hubs. A hub's screens are not a second
| level of sidebar: they are tabs across the top of every one of its pages.
| Any job is the hub, then the tab - two clicks - and the sidebar stays
| short enough to read at a glance on a busy evening.
|
| Group keys:
|   heading   section label (hidden when the sidebar is collapsed)
|   items     the hubs in the section
|
| Hub keys:
|   label     required
|   icon      name from config/icons.php
|   tabs      its screens, in the order they are reached for. The hub links
|             to the first one this person may open, and is hidden once
|             they may open none of them.
|   active    extra route-name pattern(s) that belong to the hub without
|             being a tab of it (a report's export, say)
|
| A hub with no tabs is a single screen, and takes the tab keys below.
|
| Tab keys:
|   label     required
|   route     named route
|   accounts  a path in Khana Accounts instead of a route here; hidden when
|             this install has no Accounts address
|   params    route parameters, for tabs that are one route with a
|             different segment (the reports)
|   active    route-name pattern(s) that light the tab up; defaults to its
|             own route name
|   can       permission required to see it (config/permissions.php). The
|             same permission is enforced server-side; hiding is courtesy.
|
*/

return [

    [
        'heading' => null,
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
    | The evening, in the order it happens: a table is seated, the kitchen
    | cooks, the bill is taken, the order is looked up afterwards.
    */
    [
        'heading' => 'Service',
        'items' => [
            [
                'label' => 'Floor',
                'icon' => 'building',
                'tabs' => [
                    [
                        'label' => 'Floor Plan',
                        'route' => 'admin.tables.plan',
                        'can' => 'dining.tables.view',
                    ],
                    [
                        'label' => 'Tables',
                        'route' => 'admin.tables.index',
                        // Spelled out rather than wildcarded, so the plan
                        // does not light this tab up as well.
                        'active' => ['admin.tables.index', 'admin.tables.show', 'admin.tables.edit', 'admin.tables.create'],
                        'can' => 'dining.tables.view',
                    ],
                    [
                        'label' => 'Reservations',
                        'route' => 'admin.reservations.index',
                        'active' => 'admin.reservations.*',
                        'can' => 'dining.reservations.view',
                    ],
                    [
                        'label' => 'Dining Areas',
                        'route' => 'admin.floors.index',
                        'active' => 'admin.floors.*',
                        'can' => 'dining.floors.view',
                    ],
                    [
                        'label' => 'QR Codes',
                        'route' => 'admin.qr.index',
                        'active' => 'admin.qr.*',
                        'can' => 'dining.qr.view',
                    ],
                ],
            ],
            [
                'label' => 'Kitchen',
                'icon' => 'zap',
                'tabs' => [
                    [
                        'label' => 'Kitchen Display',
                        'route' => 'admin.kitchen.index',
                        'active' => 'admin.kitchen.*',
                        'can' => 'kitchen.tickets.view',
                    ],
                    [
                        'label' => 'Stations',
                        'route' => 'admin.kitchen-stations.index',
                        'active' => 'admin.kitchen-stations.*',
                        'can' => 'kitchen.stations.view',
                    ],
                ],
            ],
            /*
            | Every way money is taken, in one place. Table bills before the
            | till, because in a restaurant the till is opened for a table far
            | more often than for somebody standing at it.
            */
            [
                'label' => 'Billing',
                'icon' => 'cart',
                'tabs' => [
                    [
                        'label' => 'Table Bills',
                        'route' => 'admin.table-bills.index',
                        'active' => 'admin.table-bills.*',
                        'can' => 'pos.tables.view',
                    ],
                    [
                        'label' => 'POS Billing',
                        'route' => 'admin.pos.terminal',
                        'active' => 'admin.pos.terminal',
                        'can' => 'pos.terminal.view',
                    ],
                    [
                        'label' => 'Held Sales',
                        'route' => 'admin.pos.parked',
                        'active' => 'admin.pos.parked',
                        'can' => 'pos.terminal.create',
                    ],
                    [
                        'label' => 'Day Close',
                        'route' => 'admin.registers.index',
                        'active' => 'admin.registers.*',
                        'can' => 'pos.registers.view',
                    ],
                ],
            ],
            [
                'label' => 'Orders',
                'icon' => 'list',
                'tabs' => [
                    [
                        // First, because "what is running late" is asked forty
                        // times an evening and "what did we sell last Tuesday"
                        // once a month.
                        'label' => 'Live Orders',
                        'route' => 'admin.live-orders.index',
                        'active' => 'admin.live-orders.*',
                        'can' => 'sales.orders.view',
                    ],
                    [
                        // Every channel, not only the storefront's.
                        'label' => 'All Orders',
                        'route' => 'admin.orders.index',
                        'active' => 'admin.orders.*',
                        'can' => 'sales.orders.view',
                    ],
                    [
                        'label' => 'Invoices',
                        'route' => 'admin.invoices.index',
                        'active' => ['admin.invoices.*', 'admin.pos.manual'],
                        'can' => 'sales.invoices.view',
                    ],
                    [
                        'label' => 'Returns',
                        'route' => 'admin.sales-returns.index',
                        'active' => 'admin.sales-returns.*',
                        'can' => 'sales.returns.view',
                    ],
                ],
            ],
        ],
    ],

    [
        'heading' => 'Business',
        'items' => [
            /*
            | What is sold and at what price. Stock, recipes and purchasing
            | are ERP's; this is the menu as the guest and the till see it.
            */
            [
                'label' => 'Menu',
                'icon' => 'package',
                'tabs' => [
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
                        'label' => 'Add-ons',
                        'route' => 'admin.modifiers.index',
                        'active' => 'admin.modifiers.*',
                        'can' => 'inventory.modifiers.view',
                    ],
                    [
                        'label' => 'Price Lists',
                        'route' => 'admin.price-lists.index',
                        'active' => 'admin.price-lists.*',
                        'can' => 'inventory.price_lists.view',
                    ],
                    [
                        'label' => 'Brands',
                        'route' => 'admin.brands.index',
                        'active' => 'admin.brands.*',
                        'can' => 'inventory.brands.view',
                    ],
                    [
                        'label' => 'Labels',
                        'route' => 'admin.labels.index',
                        'active' => 'admin.labels.*',
                        'can' => 'pos.labels.view',
                    ],
                ],
            ],
            /*
            | A regular, everything about them: who they are, what they owe,
            | what they have earned, what they said.
            */
            [
                'label' => 'Customers',
                'icon' => 'users',
                'tabs' => [
                    [
                        'label' => 'Customers',
                        'route' => 'admin.customers.index',
                        'active' => 'admin.customers.*',
                        'can' => 'crm.customers.view',
                    ],
                    [
                        'label' => 'Credit & Dues',
                        'route' => 'admin.dues.index',
                        'active' => 'admin.dues.*',
                        'can' => 'crm.dues.view',
                    ],
                    [
                        'label' => 'Ledger',
                        'route' => 'admin.ledger.index',
                        'active' => 'admin.ledger.*',
                        'can' => 'crm.ledger.view',
                    ],
                    [
                        'label' => 'Loyalty',
                        'route' => 'admin.loyalty.index',
                        'active' => 'admin.loyalty.*',
                        'can' => 'crm.loyalty.view',
                    ],
                    [
                        'label' => 'Feedback',
                        'route' => 'admin.feedback.index',
                        'active' => 'admin.feedback.*',
                        'can' => 'crm.feedback.view',
                    ],
                    [
                        'label' => 'Reminders',
                        'route' => 'admin.reminders.index',
                        'active' => 'admin.reminders.*',
                        'can' => 'crm.reminders.view',
                    ],
                ],
            ],
            /* Bringing guests back: what is offered and how it is sent. */
            [
                'label' => 'Marketing',
                'icon' => 'tag',
                'tabs' => [
                    [
                        'label' => 'Campaigns',
                        'route' => 'admin.campaigns.index',
                        'active' => 'admin.campaigns.*',
                        'can' => 'crm.campaigns.view',
                    ],
                    [
                        'label' => 'Coupons & Offers',
                        'route' => 'admin.coupons.index',
                        'active' => 'admin.coupons.*',
                        'can' => 'sales.coupons.view',
                    ],
                    [
                        'label' => 'Email Templates',
                        'route' => 'admin.email.templates.index',
                        'active' => 'admin.email.templates.*',
                        'can' => 'email.templates.view',
                    ],
                    [
                        'label' => 'Email Log',
                        'route' => 'admin.email.logs.index',
                        'active' => 'admin.email.logs.*',
                        'can' => 'email.logs.view',
                    ],
                ],
            ],
            /*
            | Money that came in. The books themselves - chart of accounts,
            | journals - are ERP's.
            */
            [
                'label' => 'Payments',
                'icon' => 'wallet',
                'tabs' => [
                    [
                        'label' => 'Payments',
                        'route' => 'admin.payments.index',
                        'active' => 'admin.payments.*',
                        'can' => 'finance.payments.view',
                    ],
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
                ],
            ],
            /*
            | Every report is one route with a different segment, so the tabs
            | carry `params` and only the open one is lit.
            */
            [
                'label' => 'Reports',
                'icon' => 'chart',
                'active' => 'admin.reports.*',
                'tabs' => [
                    [
                        'label' => 'Overview',
                        'route' => 'admin.reports.index',
                        'can' => 'reports.sales_report.view',
                    ],
                    [
                        'label' => 'Sales',
                        'route' => 'admin.reports.show',
                        'params' => ['report' => 'sales'],
                        'can' => 'reports.sales_report.view',
                    ],
                    [
                        'label' => 'Items Sold',
                        'route' => 'admin.reports.show',
                        'params' => ['report' => 'products'],
                        'can' => 'reports.sales_report.view',
                    ],
                    [
                        'label' => 'Profit',
                        'route' => 'admin.reports.show',
                        'params' => ['report' => 'profit'],
                        'can' => 'reports.profit_report.view',
                    ],
                    [
                        'label' => 'Payment Methods',
                        'route' => 'admin.reports.show',
                        'params' => ['report' => 'payments'],
                        'can' => 'reports.payment_report.view',
                    ],
                    [
                        'label' => 'Day Closing',
                        'route' => 'admin.reports.show',
                        'params' => ['report' => 'day-closing'],
                        'can' => 'reports.payment_report.view',
                    ],
                    [
                        'label' => 'Dues',
                        'route' => 'admin.reports.show',
                        'params' => ['report' => 'outstanding'],
                        'can' => 'reports.dues_report.view',
                    ],
                    [
                        'label' => 'Tax / GST',
                        'route' => 'admin.reports.show',
                        'params' => ['report' => 'tax'],
                        'can' => 'reports.tax_report.view',
                    ],
                    [
                        'label' => 'Returns',
                        'route' => 'admin.reports.show',
                        'params' => ['report' => 'returns'],
                        'can' => 'reports.returns_report.view',
                    ],
                ],
            ],
        ],
    ],

    [
        'heading' => 'Setup',
        'items' => [
            /*
            | The public site (§19): "change something on the website" is one
            | errand. Demo Requests is an inbox rather than copy, but it is
            | where the website sends people, so it is where somebody looks
            | for what the website collected.
            */
            [
                'label' => 'Website',
                'icon' => 'globe',
                'tabs' => [
                    [
                        'label' => 'Sliders',
                        'route' => 'admin.sliders.index',
                        'active' => 'admin.sliders.*',
                        'can' => 'content.sliders.view',
                    ],
                    [
                        'label' => 'Services',
                        'route' => 'admin.services.index',
                        'active' => 'admin.services.*',
                        'can' => 'content.services.view',
                    ],
                    [
                        'label' => 'FAQs',
                        'route' => 'admin.faqs.index',
                        'active' => 'admin.faqs.*',
                        'can' => 'content.faqs.view',
                    ],
                    [
                        'label' => 'Testimonials',
                        'route' => 'admin.testimonials.index',
                        'active' => 'admin.testimonials.*',
                        'can' => 'content.testimonials.view',
                    ],
                    [
                        'label' => 'Trust Numbers',
                        'route' => 'admin.landing-stats.index',
                        'active' => 'admin.landing-stats.*',
                        'can' => 'content.stats.view',
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
            /*
            | How this install is set up. The team and the plan are not here:
            | they are Khana Accounts', one entry below.
            */
            [
                'label' => 'Settings',
                'icon' => 'settings',
                'tabs' => [
                    [
                        'label' => 'General',
                        'route' => 'admin.settings.company',
                        'active' => 'admin.settings.*',
                        'can' => 'settings.general.view',
                    ],
                    [
                        'label' => 'Outlets',
                        'route' => 'admin.shops.index',
                        'active' => 'admin.shops.*',
                        'can' => 'settings.shops.view',
                    ],
                    [
                        'label' => 'Tax / GST',
                        'route' => 'admin.taxes.index',
                        'active' => 'admin.taxes.*',
                        'can' => 'finance.taxes.view',
                    ],
                    [
                        'label' => 'Printers',
                        'route' => 'admin.printers.index',
                        'active' => 'admin.printers.index',
                        'can' => 'settings.printers.view',
                    ],
                    [
                        // Opened during service when a ticket did not arrive.
                        'label' => 'Print Log',
                        'route' => 'admin.printers.jobs',
                        'active' => 'admin.printers.jobs',
                        'can' => 'settings.printers.view',
                    ],
                    [
                        'label' => 'Roles',
                        'route' => 'admin.roles.index',
                        'active' => 'admin.roles.*',
                        'can' => 'settings.roles.view',
                    ],
                    [
                        'label' => 'API Tokens',
                        'route' => 'admin.api-tokens.index',
                        'active' => 'admin.api-tokens.*',
                        'can' => 'settings.api_tokens.view',
                    ],
                    [
                        'label' => 'Activity Log',
                        'route' => 'admin.activity.index',
                        'active' => 'admin.activity.*',
                        'can' => 'settings.activity_logs.view',
                    ],
                    [
                        // What the machine did while nobody was watching.
                        // Platform staff only - see the permission.
                        'label' => 'System Health',
                        'route' => 'admin.health.index',
                        'active' => 'admin.health.*',
                        'can' => 'settings.health.view',
                    ],
                ],
            ],
            /*
            | The business, its outlets, its team and its plan (§4, §21) are
            | Khana Accounts' screens. Hidden on an install that has not been
            | told where Accounts is.
            */
            [
                'label' => 'Business & team',
                'icon' => 'users',
                'accounts' => '/',
                'can' => 'settings.shops.view',
            ],
            /*
            | The support desk (§2). One entry for both ends of the desk: the
            | screen shows more to whoever holds `support.tickets.manage`.
            */
            [
                'label' => 'Support',
                'icon' => 'help',
                'route' => 'admin.support.index',
                'active' => 'admin.support.*',
                'can' => 'support.tickets.view',
            ],
        ],
    ],

];
