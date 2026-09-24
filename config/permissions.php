<?php

/*
|--------------------------------------------------------------------------
| Permission taxonomy
|--------------------------------------------------------------------------
|
| Single source of truth for every permission in the system. The seeder,
| the role permission matrix UI, the sidebar filter and the route guards
| are all generated from this file - nothing is hard-coded elsewhere.
|
| Permission names follow:  module.submodule.action     e.g. sales.orders.approve
|
| Adding a capability is one line here plus `php artisan permissions:sync`.
|
| Shop scoping is NOT expressed here. Which shops a user may reach is data,
| not a permission: it lives on the shop_user pivot and is enforced by
| App\Models\Concerns\BelongsToShop. A permission answers "may they do this
| at all"; the pivot answers "to whose data".
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Action vocabulary
    |--------------------------------------------------------------------------
    |
    | Every action a sub-module can expose. Sub-modules opt in to the subset
    | that makes sense for them; a report has no "create", an order does not
    | get "import".
    |
    | The lower block is the set of elevated rights the SRS calls out
    | separately - the ones that move money, stock or an audit trail, and so
    | should be grantable on their own rather than riding along with "edit".
    |
    */

    'actions' => [
        'view' => ['label' => 'View', 'tone' => 'info'],
        'create' => ['label' => 'Create', 'tone' => 'success'],
        'edit' => ['label' => 'Edit', 'tone' => 'warning'],
        'delete' => ['label' => 'Delete', 'tone' => 'danger'],
        'approve' => ['label' => 'Approve', 'tone' => 'success'],
        'reject' => ['label' => 'Reject', 'tone' => 'danger'],
        'import' => ['label' => 'Import', 'tone' => 'brand'],
        'export' => ['label' => 'Export', 'tone' => 'brand'],
        'print' => ['label' => 'Print', 'tone' => 'default'],
        'download' => ['label' => 'Download', 'tone' => 'default'],

        /* Elevated: each one is auditable and separately grantable. */
        'discount' => ['label' => 'Discount Override', 'tone' => 'warning'],
        'credit' => ['label' => 'Credit Sale', 'tone' => 'warning'],
        'refund' => ['label' => 'Refund', 'tone' => 'danger'],
        'adjust' => ['label' => 'Payment Adjustment', 'tone' => 'danger'],
        'write_off' => ['label' => 'Write Off Dues', 'tone' => 'danger'],
        'cancel' => ['label' => 'Cancel / Reverse', 'tone' => 'danger'],

        /*
        | Work the other side of a queue: see every company's rows rather than
        | your own, and act on them as the platform. Elevated because it is a
        | cross-tenant read, which is the one thing the rest of this file is
        | built to prevent - see the header note on shop scoping.
        */
        'manage' => ['label' => 'Manage (Platform)', 'tone' => 'danger'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Modules
    |--------------------------------------------------------------------------
    */

    'modules' => [

        'dashboard' => [
            'label' => 'Dashboard',
            'icon' => 'grid',
            'submodules' => [
                'overview' => [
                    'label' => 'Overview',
                    'actions' => ['view', 'export'],
                ],
            ],
        ],

        /*
        | Point of sale. Kept as its own module rather than folded into
        | Sales because the counter is a distinct job: a cashier needs the
        | terminal and nothing else, and the three elevated rights below are
        | exactly the ones a shop wants to withhold from one.
        */
        'pos' => [
            'label' => 'Point of Sale',
            'icon' => 'cart',
            'submodules' => [
                'terminal' => [
                    'label' => 'Billing Terminal',
                    // create   - complete a sale
                    // discount - override the configured price/discount
                    // credit   - bill against a customer's credit limit
                    // refund   - take a return at the counter
                    'actions' => ['view', 'create', 'print', 'discount', 'credit', 'refund'],
                ],
                'registers' => [
                    'label' => 'Cash Register / Day Close',
                    'actions' => ['view', 'create', 'edit', 'approve', 'export'],
                ],
                'labels' => [
                    'label' => 'Barcode Labels',
                    'actions' => ['view', 'print'],
                ],
                /*
                 | Settling a table (§6).
                 |
                 | Under the till rather than under the room, because what it
                 | does is take money - but gated by the `dining` module, since
                 | a branch with no tables has none to bill. See
                 | config/modules.php.
                 |
                 |   order      take an order at the table, the way a captain
                 |              does with a pad - the guest's phone is not the
                 |              only way food reaches the kitchen
                 |   settle     raise the bill, in full or as a split
                 |   move       move a ticket between tables, and merge two
                 |              tables onto one bill
                 |   write_off  clear a table that left without paying
                 |
                 | write_off is separated because it is the only one that ends
                 | a sitting with money owed and no document raised. It matches
                 | the elevated pattern in RolePermissionSeeder, so no role
                 | picks it up by accident.
                 */
                'tables' => [
                    'label' => 'Table Billing',
                    'actions' => ['view', 'order', 'settle', 'move', 'write_off', 'print'],
                ],
            ],
        ],

        /*
        | The room: dining areas, tables and the QR codes on them.
        |
        | Its own module rather than a corner of Settings, because the rights
        | split three ways and the people holding them are different people.
        | Creating a table is a manager's decision made once; changing a
        | table's status is what the front of house does forty times an
        | evening; regenerating a QR invalidates every sticker already on the
        | table and belongs with whoever can reprint them.
        */
        'dining' => [
            'label' => 'Tables & QR',
            'icon' => 'grid',
            'submodules' => [
                'floors' => [
                    'label' => 'Dining Areas / Floors',
                    'actions' => ['view', 'create', 'edit', 'delete'],
                ],
                'tables' => [
                    'label' => 'Tables & Floor Plan',
                    // edit   - the table itself: name, seats, which area
                    // adjust - its state on the plan: seat, reserve, clear
                    //
                    // Split because a captain has to be able to seat a party
                    // without also being able to renumber the restaurant.
                    'actions' => ['view', 'create', 'edit', 'delete', 'adjust', 'export'],
                ],
                'qr' => [
                    'label' => 'Table QR Codes',
                    // create - issue or regenerate, which withdraws the old
                    //          code and kills every sticker already printed
                    // delete - withdraw without replacing
                    'actions' => ['view', 'create', 'delete', 'print', 'export'],
                ],
                /*
                | Bookings (§7, §21).
                |
                | Under dining because a booking is a table promised for a
                | time, and a shop with no floor plan has nothing to promise.
                |
                | `adjust` is the host's action - seat them, mark a no-show,
                | close the booking - and is split from `edit` for the same
                | reason it is on tables: somebody at the door has to be able
                | to seat a party without also being able to rewrite the
                | evening's book.
                */
                'reservations' => [
                    'label' => 'Reservations',
                    'actions' => ['view', 'create', 'edit', 'delete', 'adjust', 'export'],
                ],
            ],
        ],

        /*
        | The kitchen (§9).
        |
        | Its own module and not a corner of `dining`, because a cloud kitchen
        | with no dining room still cooks: it takes delivery orders, it routes
        | them to a tandoor and a bakery, and it needs this screen exactly as
        | much as a restaurant with forty covers does.
        |
        | Three rights, held by three different people:
        |
        |   view     the screen itself - every cook on the line
        |   advance  bumping work along - the same people
        |   recall   sending a ticket back down the ladder, which is §9's
        |            "reopen only for authorised staff". A head chef or a
        |            manager, not whoever is nearest the screen, because it
        |            rewrites the timestamps the preparation-time report is
        |            built from.
        |
        | Stations are separate again: deciding that desserts come off the
        | bakery is a decision made once when the outlet is set up, by
        | somebody who is not working the line that evening.
        */
        'kitchen' => [
            'label' => 'Kitchen',
            'icon' => 'clock',
            'submodules' => [
                'tickets' => [
                    'label' => 'Kitchen Display',
                    'actions' => ['view', 'advance', 'recall', 'print'],
                ],
                'stations' => [
                    'label' => 'Kitchen Stations',
                    'actions' => ['view', 'create', 'edit', 'delete'],
                ],
            ],
        ],

        'inventory' => [
            'label' => 'Inventory',
            'icon' => 'package',
            'submodules' => [
                'products' => [
                    'label' => 'Menu Items',
                    // Sizes are edited on the item itself and ride on `edit`:
                    // a Half and a Full are one dish, and splitting the right
                    // to price them would mean somebody who may set the price
                    // of a Full but not of a Half.
                    'actions' => ['view', 'create', 'edit', 'delete', 'import', 'export', 'print'],
                ],
                /*
                 | Add-ons and modifiers (§8). Their own right rather than a
                 | corner of products, because a question is defined once and
                 | attached to thirty dishes - editing what cheese burst costs
                 | changes every pizza on the card, and that is a decision a
                 | shop may want to keep away from whoever renames dishes.
                 */
                /*
                 | Recipes / BOM (§10).
                 |
                 | Its own right and not a corner of products, because the two
                 | are different jobs done by different people: pricing a dish
                 | is the owner's, and saying it takes 180g of paneer is the
                 | head chef's. A recipe also decides what leaves the store
                 | every evening, which is a costlier mistake than a typo in a
                 | description.
                 |
                 | No `create` or `delete`: a recipe is not a row, it is the
                 | contents of a dish, and saving it replaces what was there.
                 */
                'recipes' => [
                    'label' => 'Recipes / BOM',
                    'actions' => ['view', 'edit', 'export'],
                ],
                /*
                 | Wastage (§10).
                 |
                 | `create` is deliberately wide - a cook holding an empty tray
                 | has to be able to record it, and waste that needs a
                 | manager's attention is waste that never gets written down.
                 |
                 | `delete` is the narrow one: it puts stock back, and a right
                 | to un-record a loss is a right to hide one.
                 */
                'wastage' => [
                    'label' => 'Wastage',
                    'actions' => ['view', 'create', 'delete', 'export'],
                ],
                /*
                | Prices that apply only sometimes (§8, §16).
                |
                | Under the menu rather than under settings, because it is a
                | pricing decision a manager makes, not an installation one.
                */
                'price_lists' => [
                    'label' => 'Price Lists & Happy Hours',
                    'actions' => ['view', 'create', 'edit', 'delete'],
                ],

                'modifiers' => [
                    'label' => 'Add-ons & Modifiers',
                    'actions' => ['view', 'create', 'edit', 'delete'],
                ],
                'categories' => [
                    'label' => 'Categories',
                    'actions' => ['view', 'create', 'edit', 'delete'],
                ],
                'brands' => [
                    'label' => 'Brands',
                    'actions' => ['view', 'create', 'edit', 'delete'],
                ],
                'units' => [
                    'label' => 'Units of Measure',
                    'actions' => ['view', 'create', 'edit', 'delete'],
                ],
                'stock' => [
                    'label' => 'Stock on Hand',
                    'actions' => ['view', 'export', 'print'],
                ],
                'adjustments' => [
                    'label' => 'Stock Adjustments',
                    // Every adjustment carries a reason and an actor; approve
                    // is separate so a shop can require a second pair of eyes.
                    'actions' => ['view', 'create', 'edit', 'delete', 'approve', 'reject', 'export'],
                ],
                'batches' => [
                    'label' => 'Batches & Expiry',
                    'actions' => ['view', 'create', 'edit', 'delete', 'export'],
                ],
                'transfers' => [
                    'label' => 'Stock Transfers',
                    'actions' => ['view', 'create', 'edit', 'delete', 'approve', 'reject', 'export', 'print'],
                ],
                'warehouses' => [
                    'label' => 'Warehouses',
                    'actions' => ['view', 'create', 'edit', 'delete', 'export'],
                ],
            ],
        ],

        'sales' => [
            'label' => 'Sales',
            'icon' => 'cart',
            'submodules' => [
                'invoices' => [
                    'label' => 'Invoices',
                    // cancel rather than delete is the intended reversal;
                    // delete exists for the rare mis-keyed draft only.
                    'actions' => [
                        'view', 'create', 'edit', 'delete', 'approve',
                        'export', 'print', 'download', 'discount', 'credit', 'cancel',
                    ],
                ],
                'returns' => [
                    'label' => 'Sales Returns',
                    'actions' => ['view', 'create', 'edit', 'delete', 'approve', 'reject', 'print', 'refund'],
                ],
                'orders' => [
                    'label' => 'Online Orders',
                    'actions' => ['view', 'create', 'edit', 'delete', 'approve', 'reject', 'export', 'print'],
                ],
                'coupons' => [
                    'label' => 'Coupons & Offers',
                    'actions' => ['view', 'create', 'edit', 'delete', 'export'],
                ],
            ],
        ],

        'crm' => [
            'label' => 'Customers',
            'icon' => 'users',
            'submodules' => [
                'customers' => [
                    'label' => 'Customer Master',
                    'actions' => ['view', 'create', 'edit', 'delete', 'import', 'export'],
                ],
                'ledger' => [
                    'label' => 'Customer Ledger',
                    'actions' => ['view', 'export', 'print'],
                ],
                'dues' => [
                    'label' => 'Credit & Dues',
                    // edit      - change a credit limit or a due date
                    // write_off - forgive an outstanding balance
                    'actions' => ['view', 'edit', 'export', 'write_off'],
                ],
                'reminders' => [
                    'label' => 'Payment Reminders',
                    'actions' => ['view', 'create', 'edit', 'delete', 'export'],
                ],

                /*
                | Loyalty points (§15, §21).
                |
                |   view    see a customer's balance and passbook
                |   edit    run the programme: set the rates, switch it on
                |   adjust  put points on or take them off by hand
                |
                | `adjust` is separate and deliberately elevated: it is the
                | one action that creates value out of nothing, and a till
                | operator who could grant themselves points is a fraud
                | waiting to be discovered by an accountant.
                */
                'loyalty' => [
                    'label' => 'Loyalty Points',
                    'actions' => ['view', 'edit', 'adjust', 'export'],
                ],

                /*
                | What guests thought (§15).
                |
                | `edit` is answering a complaint, which is the only write
                | this screen has - nobody may change what a guest said.
                */
                'feedback' => [
                    'label' => 'Guest Feedback',
                    'actions' => ['view', 'edit', 'delete', 'export'],
                ],

                /*
                | Marketing messages (§15, §21).
                |
                | `create` writes a draft and costs nothing. `approve` is what
                | actually sends it, and they are split because those are two
                | very different mistakes: a bad draft is edited, and a
                | campaign sent to four hundred people is not recallable.
                */
                'campaigns' => [
                    'label' => 'Campaigns',
                    'actions' => ['view', 'create', 'edit', 'delete', 'approve'],
                ],
            ],
        ],

        'purchasing' => [
            'label' => 'Purchasing',
            'icon' => 'truck',
            'submodules' => [
                'purchase_orders' => [
                    'label' => 'Purchase Orders',
                    'actions' => ['view', 'create', 'edit', 'delete', 'approve', 'reject', 'export', 'print'],
                ],
                'suppliers' => [
                    'label' => 'Suppliers',
                    'actions' => ['view', 'create', 'edit', 'delete', 'import', 'export'],
                ],
                'receipts' => [
                    'label' => 'Goods Receipts',
                    'actions' => ['view', 'create', 'edit', 'delete', 'approve', 'print'],
                ],
                'bills' => [
                    'label' => 'Purchase Invoices',
                    'actions' => ['view', 'create', 'edit', 'delete', 'approve', 'export', 'print', 'cancel'],
                ],
                'returns' => [
                    'label' => 'Purchase Returns',
                    'actions' => ['view', 'create', 'edit', 'delete', 'approve', 'reject', 'print'],
                ],
            ],
        ],

        'finance' => [
            'label' => 'Finance',
            'icon' => 'wallet',
            'submodules' => [
                'payments' => [
                    'label' => 'Payments',
                    // adjust is the elevated one: editing a recorded payment
                    // rewrites a customer's balance, so it is audited and
                    // granted on its own.
                    'actions' => ['view', 'create', 'edit', 'delete', 'approve', 'reject', 'export', 'print', 'adjust'],
                ],
                /*
                | Money taken online, and sent back (§4, §11).
                |
                | Separate from `payments` above, which is the customer
                | ledger - cash, cheques and what a regular owes. This is the
                | gateway's side: what guests paid from their phones, what was
                | refunded, and whether the provider's settlement matches.
                |
                | `refund` is its own action and deliberately elevated: it
                | sends real money out of the restaurant's account, and a
                | cashier who could do it unsupervised is a hole nobody would
                | find until a statement arrived.
                */
                'online_payments' => [
                    'label' => 'Online Payments & Refunds',
                    'actions' => ['view', 'refund', 'export'],
                ],

                'expenses' => [
                    'label' => 'Expenses',
                    'actions' => ['view', 'create', 'edit', 'delete', 'approve', 'export'],
                ],
                'accounts' => [
                    'label' => 'Chart of Accounts',
                    'actions' => ['view', 'create', 'edit', 'delete', 'export'],
                ],
                'taxes' => [
                    'label' => 'Tax / GST Setup',
                    'actions' => ['view', 'create', 'edit', 'delete'],
                ],
            ],
        ],

        /*
        | Reports. One sub-module per report the SRS lists, because the point
        | of splitting them is that a shop can let a cashier see their own
        | sales figures without opening the profit or the supplier ledger.
        */
        'reports' => [
            'label' => 'Reports',
            'icon' => 'chart',
            'submodules' => [
                'sales_report' => ['label' => 'Sales', 'actions' => ['view', 'export', 'print', 'download']],
                'profit_report' => ['label' => 'Profit', 'actions' => ['view', 'export', 'print', 'download']],
                'purchase_report' => ['label' => 'Purchases', 'actions' => ['view', 'export', 'print', 'download']],
                'stock_report' => ['label' => 'Stock & Valuation', 'actions' => ['view', 'export', 'print', 'download']],
                'expiry_report' => ['label' => 'Expiry & Low Stock', 'actions' => ['view', 'export', 'print', 'download']],
                'dues_report' => ['label' => 'Outstanding & Dues', 'actions' => ['view', 'export', 'print', 'download']],
                'payment_report' => ['label' => 'Payment Methods', 'actions' => ['view', 'export', 'print', 'download']],
                'tax_report' => ['label' => 'Tax / GST', 'actions' => ['view', 'export', 'print', 'download']],
                'customer_report' => ['label' => 'Customers', 'actions' => ['view', 'export', 'print', 'download']],
                'supplier_report' => ['label' => 'Suppliers', 'actions' => ['view', 'export', 'print', 'download']],
                'returns_report' => ['label' => 'Returns & Refunds', 'actions' => ['view', 'export', 'print', 'download']],
                'finance_report' => ['label' => 'Financial Summary', 'actions' => ['view', 'export', 'print', 'download']],
                /*
                 | Kitchen performance (§9).
                 |
                 | Its own submodule and not a corner of the sales report,
                 | because the people who want it are not the people who want
                 | revenue: a head chef asking why the tandoor ran twelve
                 | minutes behind on Friday has no business in the margin
                 | figures, and should not need them to find out.
                 */
                'kitchen_report' => ['label' => 'Kitchen Performance', 'actions' => ['view', 'export', 'print', 'download']],
            ],
        ],

        'content' => [
            'label' => 'Content',
            'icon' => 'file',
            'submodules' => [
                'services' => [
                    'label' => 'Services',
                    'actions' => ['view', 'create', 'edit', 'delete'],
                ],
                'faqs' => [
                    'label' => 'FAQs',
                    'actions' => ['view', 'create', 'edit', 'delete'],
                ],
                'sliders' => [
                    'label' => 'Sliders',
                    // create also covers Duplicate, which makes a new row.
                    'actions' => ['view', 'create', 'edit', 'delete'],
                ],
                'collections' => [
                    'label' => 'Collections',
                    // edit also covers the Active and Featured toggles.
                    'actions' => ['view', 'create', 'edit', 'delete'],
                ],
                'events' => [
                    'label' => 'Events',
                    'actions' => ['view', 'create', 'edit', 'delete'],
                ],
                'blogs' => [
                    'label' => 'Blog Posts',
                    // edit also covers the status and Featured controls.
                    'actions' => ['view', 'create', 'edit', 'delete'],
                ],
                'reels' => [
                    'label' => 'Instagram Reels',
                    'actions' => ['view', 'create', 'edit', 'delete'],
                ],
                /*
                | The landing page (§19).
                |
                | Five lists and an inbox, all of them feeding the public page
                | at `/`. Under `content` rather than a module of their own
                | because that is what they are - website copy - and because
                | the existing content grants then cover them without a new
                | rule: whoever already edits the sliders and the FAQs is the
                | person who edits the testimonials.
                |
                | `demo_requests` is the odd one and is deliberately separate.
                | It is not content: it holds strangers' names, phone numbers
                | and what they said about their own business, and it is the
                | only table here anybody writes to from the public internet.
                | Editing website copy should not come with a list of every
                | sales lead the company has.
                */
                'testimonials' => [
                    'label' => 'Testimonials',
                    'actions' => ['view', 'create', 'edit', 'delete'],
                ],
                'outlet_types' => [
                    'label' => 'Outlet Types',
                    'actions' => ['view', 'create', 'edit', 'delete'],
                ],
                'integrations' => [
                    'label' => 'Integrations',
                    'actions' => ['view', 'create', 'edit', 'delete'],
                ],
                'showcases' => [
                    'label' => 'Product Screenshots',
                    'actions' => ['view', 'create', 'edit', 'delete'],
                ],
                'stats' => [
                    'label' => 'Trust Numbers',
                    'actions' => ['view', 'create', 'edit', 'delete'],
                ],
                'demo_requests' => [
                    'label' => 'Demo Requests',
                    // No create: these arrive from the public form. Nobody
                    // types a lead in by hand, and an action that exists only
                    // to be granted by mistake is one worth leaving out.
                    'actions' => ['view', 'edit', 'delete', 'export'],
                ],
                'instagram' => [
                    'label' => 'Instagram Posts',
                    'actions' => ['view', 'create', 'edit', 'delete'],
                ],
            ],
        ],

        'email' => [
            'label' => 'Email',
            'icon' => 'file',
            'submodules' => [
                'templates' => [
                    'label' => 'Templates',
                    'actions' => ['view', 'create', 'edit', 'delete'],
                ],
                'logs' => [
                    'label' => 'Delivery Logs',
                    // delete covers pruning old entries; the log is otherwise
                    // append-only, so there is nothing to create or edit.
                    'actions' => ['view', 'delete', 'export'],
                ],
            ],
        ],

        'settings' => [
            'label' => 'Settings',
            'icon' => 'settings',
            'submodules' => [
                'general' => [
                    'label' => 'General Settings',
                    'actions' => ['view', 'edit'],
                ],
                /*
                | The companies on the platform, one level above branches.
                |
                | In practice only Super Admin holds this, and Super Admin
                | bypasses permission checks entirely via Gate::before - so
                | the right exists to gate the screens for everybody else.
                | A Tenant Owner administers their branches and staff, never
                | the tenant list they sit in.
                */
                'tenants' => [
                    'label' => 'Tenants (Companies)',
                    'actions' => ['view', 'create', 'edit', 'delete', 'export'],
                ],

                /*
                | What the platform sells (§21).
                |
                | Super Admin's own catalogue. A restaurant never edits a
                | plan - it reads the one it is on, through `subscription`
                | below - so this right is deliberately separate from that
                | one rather than two actions on the same submodule.
                */
                'plans' => [
                    'label' => 'Subscription Plans',
                    'actions' => ['view', 'create', 'edit', 'delete'],
                ],

                /*
                | A business's own account (§4, §21).
                |
                | Split by action rather than by role, because the same
                | screen serves both and they need different things from it:
                |
                |   view    the plan, what is left of the limits, when it
                |           runs out, and the payment history. A Tenant
                |           Owner holds this and sees their own company.
                |   edit    put somebody on a plan, renew, cancel, record a
                |           payment. Platform work; Super Admin only.
                */
                'subscriptions' => [
                    'label' => 'Subscriptions & Billing',
                    'actions' => ['view', 'edit', 'export'],
                ],

                'shops' => [
                    'label' => 'Branches',
                    // Creating and deleting a branch is deliberately its own
                    // right, separate from every other setting.
                    'actions' => ['view', 'create', 'edit', 'delete', 'export'],
                ],
                'users' => [
                    'label' => 'Users',
                    'actions' => ['view', 'create', 'edit', 'delete', 'export'],
                ],
                'sessions' => [
                    'label' => 'Login Sessions & IP Blocks',
                    // view  - see sessions, login history and IP history
                    // edit  - activate/deactivate an account
                    // delete- end a session, sign out all devices, block an IP
                    'actions' => ['view', 'edit', 'delete'],
                ],
                'roles' => [
                    'label' => 'Roles & Permissions',
                    'actions' => ['view', 'create', 'edit', 'delete'],
                ],
                /*
                | Printers and devices (§4, §6, §21).
                |
                | Under settings rather than under the kitchen, and not
                | module-gated, because a printer is a fact about the
                | premises rather than a line of business: a shop with no
                | dining room still prints bills, and one with no till still
                | prints labels.
                |
                | `print` is separate from `view` so a cashier can send a
                | test page without also being able to re-address the
                | tandoor's printer at the far end of the building.
                */
                'printers' => [
                    'label' => 'Printers & Devices',
                    'actions' => ['view', 'create', 'edit', 'delete', 'print'],
                ],

                /*
                | API tokens for integrations (§2, §21).
                |
                | A token can create orders and close dishes, so minting one
                | is a bigger act than reading the list. Super Admin and a
                | Tenant Owner hold it; nobody else has a reason to.
                */
                'api_tokens' => [
                    'label' => 'API Tokens',
                    'actions' => ['view', 'create', 'delete'],
                ],

                'activity_logs' => [
                    'label' => 'Activity Logs',
                    'actions' => ['view', 'delete', 'export'],
                ],

                /*
                | System health: failed jobs, backups and the error log (§17).
                |
                | Under `settings` and not under `support`, although both are
                | platform work, because the grants above already do the right
                | thing with it: every customer-side role is written as
                | "everything except settings.*", so `settings.health.*` lands
                | on Super Admin and Admin and on nobody else without a single
                | extra rule.
                |
                | That default matters more here than convenience. This screen
                | shows the tail of the error log and the name of every failed
                | job, which between them leak table names, file paths and the
                | shape of the deployment - none of it a restaurant's business,
                | and all of it useful to somebody probing the platform.
                |
                |   view    read the screen
                |   create  take a backup now, off-schedule
                |   edit    retry a failed job
                |   delete  discard failed jobs
                */
                'health' => [
                    'label' => 'System Health',
                    'actions' => ['view', 'create', 'edit', 'delete'],
                ],
            ],
        ],

        /*
        | The support desk (§2).
        |
        | Its own module rather than a corner of `settings`, because the two
        | audiences do not overlap with anything else in the system. A
        | restaurant owner raising a ticket about a failed settlement needs no
        | settings right at all, and the platform staff who answer it need
        | nothing else in `settings` either.
        |
        | Deliberately absent from config/modules.php, so it is never
        | module-gated: a shop that has switched off dining and purchasing can
        | still ask for help, and an outlet whose trouble is that a module will
        | not turn on must not be locked out of saying so.
        */
        'support' => [
            'label' => 'Support',
            'icon' => 'help',
            'submodules' => [
                'tickets' => [
                    'label' => 'Support Tickets',
                    /*
                    | The split that matters here is view/create against
                    | `manage`, and it is the difference between the two ends
                    | of the desk:
                    |
                    |   view/create  a restaurant, seeing and raising its own
                    |   manage       the platform's staff, seeing every
                    |                company's, replying as support, assigning,
                    |                and writing internal notes
                    |
                    | `manage` is what SupportTicket::scopeVisibleTo() reads to
                    | decide whether to narrow a query to one company, so
                    | granting it to a restaurant would show them the whole
                    | platform's tickets. It belongs to Super Admin and the
                    | people who work the queue, and to nobody else.
                    */
                    'actions' => ['view', 'create', 'edit', 'delete', 'export', 'manage'],
                ],
            ],
        ],

    ],

];
