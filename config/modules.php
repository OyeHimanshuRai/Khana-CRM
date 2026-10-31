<?php

/*
|--------------------------------------------------------------------------
| Business modules a shop can switch on
|--------------------------------------------------------------------------
|
| Not every outlet does every trade. A counter that only takes delivery has
| no use for a floor plan; a central kitchen has no till. This is the list a
| shop picks from when it is set up, and what it picks decides which modules
| its dashboard, its sidebar and its routes offer.
|
| It is deliberately NOT a second permission system. The two answer different
| questions and both have to say yes:
|
|     module      does this shop do this kind of business at all?
|     permission  may this person do it?
|
| A shop that does no purchasing hides it from everybody, including its
| owner. A shop that does hides it from a cashier, because the cashier has no
| permission. Neither is a substitute for the other, and collapsing them
| would mean either "turn it off for the shop" leaks to whoever holds the
| right, or "grant the right" silently turns a line of business on.
|
| Keys:
|   label        shown on the shop form and in the sidebar's empty states
|   blurb        one line of what the module actually is
|   icon         name from resources/views/components/icon.blade.php
|   default      whether a brand-new shop starts with it on
|   permissions  the permission prefixes this module governs. A prefix may
|                appear under more than one module, in which case the
|                permission is allowed when ANY owning module is on.
|
| Anything not named by a `permissions` prefix here is never module-gated:
| the dashboard, settings, users, content and email are part of the platform
| rather than a line of business, and a shop cannot switch them off.
|
| Read through App\Support\Modules. Enforced by
| App\Http\Middleware\EnsureModuleIsEnabled, which derives what a route needs
| from the `permission:` middleware the route already declares - so the gate
| can never drift from the permission it is paired with.
|
*/

/*
| The menu itself - dishes, their sections, their add-ons and the units they
| are sold in. Every module that sells food needs it, so it is claimed by all
| four rather than by Inventory alone: a Starter outlet runs the till without
| a stock ledger, and a till with no way to add a dish to sell is no till.
|
| Listed in Inventory too, and that matters: the longest claimed prefix wins
| (Modules::ownersFor), so a prefix listed here and not there would take the
| menu away from a shop that runs Inventory without the till.
*/
$menu = ['inventory.products', 'inventory.categories', 'inventory.modifiers', 'inventory.units'];

/*
| Adding stock to an item from the item itself ("Restock").
|
| Claimed by the same four for the same reason as the menu: an outlet that sells
| what is already baked - a slice of cheesecake, a packaged drink - has items
| with a shelf count, and a Starter plan has no stock ledger to put more on it.
| Without this the row says "Out of stock" and offers nothing; with it, the one
| action that fixes that is available wherever the item can be sold, while the
| ledger, batches, transfers and adjustments stay Inventory's.
|
| Longer than `inventory` (Inventory's own prefix), so it wins for this one
| permission and nothing else - and is listed in Inventory too, as above.
*/
$restock = ['inventory.stock.restock'];

return [

    'retail' => [
        'label' => 'Retail',
        'blurb' => 'Orders, invoices and returns.',
        'icon' => 'cart',
        'default' => true,
        'permissions' => ['sales', ...$menu, ...$restock],
    ],

    'pos' => [
        'label' => 'POS / Billing',
        'blurb' => 'The till: fast billing, tender split and day close.',
        'icon' => 'cart',
        'default' => true,
        'permissions' => ['pos.terminal', 'pos.registers', ...$menu, ...$restock],
    ],

    /*
     | The room itself. On by default because dine-in is what this platform
     | is for; a cloud kitchen that only takes delivery unticks it and loses
     | the floor plan, the tables and the QR screen together, which is
     | exactly the right amount to lose.
     */
    'dining' => [
        'label' => 'Dine-in & Tables',
        'blurb' => 'Dining areas, tables, the floor plan and per-table QR codes.',
        'icon' => 'grid',
        'default' => true,
        /*
         | `pos.tables` is here and not under the till on purpose: billing a
         | table needs tables. A cloud kitchen unticks this and loses the floor
         | plan, the QR screen and the table-billing screen together, which is
         | exactly the right amount to lose - a screen that could only ever
         | list nothing is worse than no screen.
         */
        'permissions' => ['dining', 'pos.tables', ...$menu, ...$restock],
    ],

    /*
     | The kitchen screen and the stations behind it.
     |
     | Separate from `dining` on purpose: a cloud kitchen unticks the dining
     | room and keeps this, a bakery counter that sells what is already baked
     | unticks this and keeps the till. Neither is a strange shop.
     */
    'kitchen' => [
        'label' => 'Kitchen / KDS',
        'blurb' => 'The kitchen display, stations and KOT routing.',
        'icon' => 'clock',
        'default' => true,
        'permissions' => ['kitchen'],
    ],

    'inventory' => [
        'label' => 'Inventory',
        'blurb' => 'Menu items, stock, batches, transfers and storage locations.',
        'icon' => 'package',
        'default' => true,
        'permissions' => ['inventory', ...$menu, ...$restock],
    ],

    'barcode' => [
        'label' => 'Barcode & Labels',
        'blurb' => 'Label printing and tag design.',
        'icon' => 'tag',
        'default' => true,
        'permissions' => ['pos.labels'],
    ],

    'purchase' => [
        'label' => 'Purchase',
        'blurb' => 'Purchase orders, goods receipts, supplier bills and returns.',
        'icon' => 'truck',
        'default' => true,
        'permissions' => ['purchasing'],
    ],

    'customers' => [
        'label' => 'Customer Management',
        'blurb' => 'Customer master, ledger, credit and payment reminders.',
        'icon' => 'users',
        'default' => true,
        'permissions' => ['crm'],
    ],

    'reports' => [
        'label' => 'Reports',
        'blurb' => 'Every report and export.',
        'icon' => 'chart',
        'default' => true,
        'permissions' => ['reports'],
    ],

    /*
     | The public online shop at /shop/{slug}. A door to the outside rather
     | than a screen, so it claims no permission: what it switches is whether
     | that door exists at all (ShareStorefrontShop). The orders it brings in
     | are still Retail's to work, so an outlet that closes its storefront
     | keeps every online order it already has.
     */
    'storefront' => [
        'label' => 'Online storefront',
        'blurb' => 'Your own shop page where customers order for delivery or pickup.',
        'icon' => 'globe',
        'default' => true,
        'permissions' => [],
    ],

];
