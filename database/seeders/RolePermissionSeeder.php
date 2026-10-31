<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\PermissionRegistry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    /** Rights that move money, stock or an audit trail. */
    private const ELEVATED = '/\.(delete|approve|reject|discount|credit|refund|adjust|write_off|cancel)$/';

    /** Read-only rights. */
    private const READ_ONLY = '/\.(view|export|print|download)$/';

    /**
     * Roles created out of the box, and how their permissions are chosen.
     *
     * A closure receives every declared permission name and returns the
     * subset the role should hold. Super Admin is deliberately absent from
     * the grant logic - it bypasses checks via Gate::before instead of
     * carrying thousands of rows.
     *
     * The shop-floor roles below mirror the ones the SRS names. What none of
     * them carries is a shop: which branches a person works in is data on
     * the shop_user pivot, not a permission, so the same "Cashier" role fits
     * every till in the business.
     *
     * @return array<string, array{description: string, grant: null|callable(string): bool}>
     */
    private function definitions(): array
    {
        return [
            User::SUPER_ADMIN => [
                'description' => 'Unrestricted access to every module, action and shop.',
                'grant' => null,
            ],

            'Admin' => [
                'description' => 'Full access except role and permission administration.',
                'grant' => fn (string $p) => ! str_starts_with($p, 'settings.roles.'),
            ],

            /*
             | SRS 5: "Manage own company/tenant: branches, staff, financials
             | and overall reports."
             |
             | So the blanket settings.* exclusion has three deliberate holes,
             | and each is the thing that sentence actually asks for:
             |
             |   tenants   their own company's details. TenantController
             |             decides *whose* - the permission only opens the
             |             screen - so view and edit are safe to grant and
             |             create/delete are pointedly not: adding a business
             |             to the platform is the platform's job, and granting
             |             a right the controller then refuses is a worse
             |             experience than never offering it.
             |   shops     their branches. This is what "multi-branch" means
             |             to the person paying for it.
             |   users     their staff, and the sessions and activity behind
             |             them. Without this an owner cannot hire anybody.
             |
             | settings.roles stays out, and not as an oversight: roles are
             | defined once for the whole platform, so a tenant owner editing
             | one would be editing every other business's too.
             */
            'Tenant Owner' => [
                'description' => 'Everything inside their own company: branches, staff, financials and reports. No platform settings.',
                'grant' => fn (string $p) => ! str_starts_with($p, 'settings.')
                    || in_array($p, ['settings.tenants.view', 'settings.tenants.edit'], true)
                    /*
                    | Their own subscription, to read (§21). An owner should
                    | be able to see which plan they are on, how much of it
                    | they have used and when it runs out, without opening a
                    | support ticket to find out.
                    |
                    | `settings.subscriptions.edit` is NOT here: renewing and
                    | changing plan are the platform's side of the deal, and
                    | a customer who could extend their own term is not on a
                    | subscription. `settings.plans.*` stays out for the same
                    | reason.
                    */
                    || $p === 'settings.subscriptions.view'
                    // Paying online is different from `edit`: it only
                    // extends a term against a payment the gateway signed.
                    || $p === 'settings.subscriptions.pay'
                    || str_starts_with($p, 'settings.shops.')
                    || str_starts_with($p, 'settings.users.')
                    || str_starts_with($p, 'settings.sessions.')
                    || str_starts_with($p, 'settings.activity_logs.')
                    // The printers on their own premises; shop-scoped.
                    || str_starts_with($p, 'settings.printers.')
                    || str_starts_with($p, 'settings.general.'),
            ],

            'Shop Admin' => [
                'description' => 'Day-to-day shop operations: products, customers, billing, stock and purchasing.',
                'grant' => fn (string $p) => ! str_starts_with($p, 'settings.')
                    && ! str_starts_with($p, 'reports.profit_report.')
                    && ! preg_match('/\.(write_off|adjust)$/', $p),
            ],

            'Manager' => [
                'description' => 'Monitors sales, stock and staff, and signs off approvals. Cannot delete or write off.',
                'grant' => fn (string $p) => ! str_starts_with($p, 'settings.')
                    && ! preg_match('/\.(delete|write_off|adjust)$/', $p),
            ],

            /*
             | Cashier is the tightest of the operational roles on purpose:
             | the terminal, the customer lookup it needs to bill, and
             | nothing that would let them rewrite what they billed.
             */
            'Cashier' => [
                'description' => 'POS billing, payments and customer lookup. No discount override, credit or refund.',
                'grant' => fn (string $p) => in_array($p, [
                    'dashboard.overview.view',
                    'pos.terminal.view', 'pos.terminal.create', 'pos.terminal.print',
                    'pos.registers.view', 'pos.registers.create',
                    'pos.labels.view', 'pos.labels.print',
                    'inventory.products.view', 'inventory.stock.view',
                    'crm.customers.view', 'crm.customers.create', 'crm.customers.edit',
                    'crm.ledger.view',
                    'sales.invoices.view', 'sales.invoices.create', 'sales.invoices.print',
                    'finance.payments.view', 'finance.payments.create',
                    'reports.sales_report.view',
                    /*
                     | The kitchen board, read-only. A cashier telling a guest
                     | "it is on the pass" beats walking to the kitchen to
                     | ask, and reprinting a KOT is a counter job. Bumping is
                     | not: a till that could mark food ready would mark it
                     | ready when the guest asked, not when it was.
                     */
                    'kitchen.tickets.view', 'kitchen.tickets.print',
                    /*
                     | Settling a table is the counter's job (§17.6): the floor
                     | takes the order, the counter takes the money. Not
                     | write_off - money owed stops being owed, and that is a
                     | manager's call, not the till's.
                     */
                    'pos.tables.view', 'pos.tables.settle', 'pos.tables.print',
                ], true),
            ],

            /*
             | The two roles §12 names that a jewellery ERP had no use for.
             |
             | Neither carries a shop. Which branch somebody works in is data
             | on the shop_user pivot, not a permission, so one "Kitchen
             | Staff" role fits every kitchen in the business.
             */
            'Kitchen Staff' => [
                'description' => 'The kitchen display: accept, cook and mark ready. No prices, no menu edits.',
                'grant' => fn (string $p) => in_array($p, [
                    'dashboard.overview.view',
                    'kitchen.tickets.view', 'kitchen.tickets.advance', 'kitchen.tickets.print',
                    'kitchen.stations.view',
                    /*
                     | View, not edit. The sold-out toggle rides on
                     | inventory.products.edit - see the route - and granting
                     | that here would hand every cook the price of every
                     | dish. A kitchen that needs the toggle gets `edit`
                     | ticked on deliberately; it is not worth smuggling in.
                     */
                    'inventory.products.view', 'inventory.modifiers.view',
                    'dining.tables.view',
                ], true),
            ],

            'Captain / Waiter' => [
                'description' => 'The floor: seat and clear tables, take orders, watch the pass. No billing.',
                'grant' => fn (string $p) => in_array($p, [
                    'dashboard.overview.view',
                    'dining.tables.view', 'dining.tables.adjust',
                    'dining.floors.view', 'dining.qr.view',
                    /*
                     | Advance, because it is the runner who knows a plate
                     | actually reached the table - and Served is the last
                     | rung. A kitchen marking its own food served would be
                     | marking that it put it on the pass.
                     */
                    'kitchen.tickets.view', 'kitchen.tickets.advance',
                    'inventory.products.view', 'inventory.modifiers.view',
                    'sales.orders.view', 'sales.orders.create', 'sales.orders.edit',
                    'crm.customers.view', 'crm.customers.create',
                    /*
                     | The captain's pad (§17.6): Take order on the bill screen,
                     | and moving or merging a party (§17.5). Opening that
                     | screen needs `view`; taking the money stays `settle`,
                     | which is the counter's.
                     */
                    'pos.tables.view', 'pos.tables.order', 'pos.tables.move',
                ], true),
            ],

            'Sales Executive' => [
                'description' => 'Customer management, quotations, orders and follow-up. No stock or finance edits.',
                'grant' => fn (string $p) => str_starts_with($p, 'crm.')
                    || str_starts_with($p, 'sales.orders.')
                    || in_array($p, [
                        'dashboard.overview.view',
                        'inventory.products.view', 'inventory.stock.view',
                        'sales.invoices.view', 'sales.invoices.create', 'sales.invoices.print',
                        'sales.coupons.view',
                        'reports.sales_report.view', 'reports.customer_report.view',
                        'reports.dues_report.view',
                    ], true),
            ],

            'Warehouse' => [
                'description' => 'Receiving, batches and expiry, stock movement, transfers and dispatch.',
                'grant' => fn (string $p) => str_starts_with($p, 'inventory.')
                    || str_starts_with($p, 'purchasing.receipts.')
                    || in_array($p, [
                        'dashboard.overview.view',
                        'purchasing.purchase_orders.view', 'purchasing.suppliers.view',
                        'purchasing.returns.view', 'purchasing.returns.create',
                        'sales.orders.view', 'sales.orders.edit',
                        'sales.returns.view', 'sales.returns.create',
                        'reports.stock_report.view', 'reports.stock_report.export',
                        'reports.expiry_report.view', 'reports.expiry_report.export',
                    ], true),
            ],

            'Accountant' => [
                'description' => 'Payments, dues, expenses and financial reports, including write-offs.',
                'grant' => fn (string $p) => str_starts_with($p, 'finance.')
                    || str_starts_with($p, 'reports.')
                    || str_starts_with($p, 'crm.ledger.')
                    || str_starts_with($p, 'crm.dues.')
                    || str_starts_with($p, 'crm.reminders.')
                    || in_array($p, [
                        'dashboard.overview.view',
                        'crm.customers.view', 'crm.customers.export',
                        'purchasing.bills.view', 'purchasing.bills.export',
                        'purchasing.suppliers.view',
                        'sales.invoices.view', 'sales.invoices.export', 'sales.invoices.print',
                    ], true),
            ],

            'Employee' => [
                'description' => 'Create and edit operational records. Cannot approve, delete or override.',
                'grant' => fn (string $p) => ! str_starts_with($p, 'settings.')
                    && ! preg_match(self::ELEVATED, $p),
            ],

            'Auditor' => [
                'description' => 'Read-only across the system, including reports and logs.',
                'grant' => fn (string $p) => (bool) preg_match(self::READ_ONLY, $p),
            ],
        ];
    }

    /**
     * The only roles that may hold a platform-only right.
     *
     * Super Admin is deliberately absent: its grant is null, which short-
     * circuits the whole filter, so it never reaches the check below.
     */
    private const PLATFORM_ROLES = ['Admin'];

    /**
     * Is this a right that crosses the company boundary?
     *
     * ------------------------------------------------------------------
     * Why this is a backstop and not a line in each role
     * ------------------------------------------------------------------
     *
     * Most grants here are written as exclusions - "everything except
     * settings.*". That shape is right for a role and wrong for a permission
     * that must never be given out, because the default is *yes*: a new
     * cross-tenant right added to config/permissions.php lands in Tenant
     * Owner, Shop Admin and Manager the moment somebody runs the seeder, and
     * nothing in this file would say so.
     *
     * `support.tickets.manage` is the first of them. It is what
     * SupportTicket::scopeVisibleTo() reads to decide whether to narrow a
     * query to one company, so a restaurant owner holding it would see every
     * other restaurant's support threads - including what they told us about
     * their takings.
     *
     * So the rule is inverted here, once: a platform-only right is refused to
     * every role that is not on the list above, whatever that role's own
     * grant says. Adding another one is a line in this method rather than an
     * edit to nine closures, and forgetting to add it fails closed.
     */
    private function isPlatformOnly(string $permission): bool
    {
        return str_ends_with($permission, '.manage') || str_starts_with($permission, 'platform.');
    }

    /**
     * The roles that hold one permission out of the box.
     *
     * Used by `permissions:sync` to hand a newly declared right to the roles
     * that should have it, without re-syncing every role and so without
     * overwriting what somebody has since changed in the role editor. Super
     * Admin is not listed: it passes every check on its own.
     *
     * @return array<int, string>
     */
    public function defaultRolesFor(string $permission): array
    {
        $roles = [];

        foreach ($this->definitions() as $roleName => $definition) {
            if ($definition['grant'] === null) {
                continue;
            }

            if ($this->isPlatformOnly($permission) && ! in_array($roleName, self::PLATFORM_ROLES, true)) {
                continue;
            }

            if (($definition['grant'])($permission)) {
                $roles[] = $roleName;
            }
        }

        return $roles;
    }

    /**
     * Roles that changed name when the platform became multi-tenant.
     *
     * Renamed in place rather than redefined, because a role is not just a
     * name: users hold it. Leaving the old row behind and creating a new one
     * would silently strip everyone who had "Shop Owner" of their access
     * while an empty "Tenant Owner" sat beside it looking correct.
     *
     * @return array<string, string> old name => new name
     */
    private function renames(): array
    {
        return [
            // A branch is no longer the top of the tree; the company is.
            'Shop Owner' => 'Tenant Owner',
            // The SRS names this role Employee.
            'Staff' => 'Employee',
        ];
    }

    /**
     * Apply the renames, before anything looks a role up by name.
     */
    private function rename(string $guard): void
    {
        foreach ($this->renames() as $from => $to) {
            $old = Role::where('name', $from)->where('guard_name', $guard)->first();

            if (! $old) {
                continue;
            }

            // If both exist the rename already happened and something has
            // since recreated the old one. Renaming onto a taken name would
            // violate the unique index, so leave it for a human to look at.
            if (Role::where('name', $to)->where('guard_name', $guard)->exists()) {
                $this->command?->warn(sprintf('  Both "%s" and "%s" exist; left alone.', $from, $to));

                continue;
            }

            $old->forceFill(['name' => $to])->save();

            $this->command?->info(sprintf('  Role "%s" renamed to "%s".', $from, $to));
        }
    }

    public function run(): void
    {
        $guard = config('auth.defaults.guard', 'web');

        $this->rename($guard);

        // Create every permission declared in config/permissions.php.
        $names = PermissionRegistry::names();

        DB::transaction(function () use ($names, $guard) {
            $rows = $names->map(fn (string $name) => [
                'name' => $name,
                'guard_name' => $guard,
                'created_at' => now(),
                'updated_at' => now(),
            ])->all();

            // upsert keeps this re-runnable without touching existing rows.
            Permission::upsert($rows, ['name', 'guard_name'], ['updated_at']);

            /*
             | Spatie resolves permission names against its own cache, which
             | an upsert does not touch. Without this, syncPermissions() below
             | cannot see anything added in this run and throws
             | PermissionDoesNotExist on the very permissions just written.
             */
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            /*
             | name => id, read once.
             |
             | syncPermissions() resolves every name against Spatie's cached
             | collection one at a time - four hundred names, a dozen roles,
             | and a filter over the whole collection for each. That was 3 of
             | the 3.7 seconds every test spent before its first assertion.
             | The ids are written straight to the pivot instead: the same
             | rows, in one diff per role. Equivalent because permission
             | events are off (config/permission.php) and the cache is
             | forgotten below.
             */
            $ids = Permission::query()->where('guard_name', $guard)->pluck('id', 'name');

            foreach ($this->definitions() as $roleName => $definition) {
                $role = Role::firstOrCreate(
                    ['name' => $roleName, 'guard_name' => $guard]
                );

                if ($definition['grant'] === null) {
                    continue;
                }

                $granted = $names
                    ->filter($definition['grant'])
                    ->reject(fn (string $p) => $this->isPlatformOnly($p) && ! in_array($roleName, self::PLATFORM_ROLES, true))
                    ->values()
                    ->all();

                $role->permissions()->sync($ids->only($granted)->values()->all());
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Promote the seeded administrator.
        $admin = User::where('email', config('admin.default_email'))->first();

        if ($admin && ! $admin->hasRole(User::SUPER_ADMIN)) {
            $admin->assignRole(User::SUPER_ADMIN);
        }

        $this->command?->info(sprintf(
            '  %d permissions across %d roles.',
            $names->count(),
            count($this->definitions())
        ));
    }
}
