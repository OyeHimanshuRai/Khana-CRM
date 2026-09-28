<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\PermissionRegistry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
     * The key Accounts gives each CRM preset (App\Support\Roles there).
     *
     * Accounts records which role a person holds and hands the key over at
     * sign-in; the rights stay here. Any role not listed - one a business
     * made itself, or one Accounts does not offer - is keyed by its name.
     */
    public const ACCOUNTS_KEYS = [
        'Tenant Owner' => 'owner',
        'Manager' => 'manager',
        'Cashier' => 'cashier',
        'Captain / Waiter' => 'captain',
        'Kitchen Staff' => 'kitchen',
        'Auditor' => 'auditor',
    ];

    /*
     | Rights that belong to the platform rather than to a customer.
     |
     | `settings.*` was always one of these. `content.*` is the other, and it
     | was missed because the name reads like a restaurant's own content: it
     | is not. Content drives THIS product's marketing site - the sliders,
     | blog posts, FAQs, testimonials and services on the page a restaurant
     | owner reads before signing up. LandingController is its only reader.
     |
     | Left granted, every Tenant Owner on the platform could edit the front
     | page everybody else is sold on, and the first self-serve signup made
     | that reachable by anyone with a card. A restaurant's own public pages
     | are its storefront and its table QR menu, and neither is here.
     */
    private static function platformOnly(string $permission): bool
    {
        return str_starts_with($permission, 'settings.')
            || str_starts_with($permission, 'content.');
    }

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
             | So the blanket settings.* exclusion has deliberate holes, each
             | the thing that sentence actually asks for:
             |
             |   shops          their branches. This is what "multi-branch"
             |                  means to the person paying for it.
             |   activity_logs  what their staff did.
             |   general        their own settings.
             |
             | Their company's details, their staff and their subscription
             | used to be holes here too. All three are Khana Accounts'
             | screens now, where the owner is the owner.
             |
             | settings.roles stays out, and not as an oversight: roles are
             | defined once for the whole platform, so a tenant owner editing
             | one would be editing every other business's too.
             */
            'Tenant Owner' => [
                'description' => 'Everything inside their own company: branches, financials and reports. No platform settings.',
                'grant' => fn (string $p) => ! self::platformOnly($p)
                    || str_starts_with($p, 'settings.shops.')
                    || str_starts_with($p, 'settings.activity_logs.')
                    || str_starts_with($p, 'settings.general.'),
            ],

            'Shop Admin' => [
                'description' => 'Day-to-day shop operations: products, customers, billing and stock.',
                'grant' => fn (string $p) => ! self::platformOnly($p)
                    && ! str_starts_with($p, 'reports.profit_report.')
                    && ! preg_match('/\.(write_off|adjust)$/', $p),
            ],

            'Manager' => [
                'description' => 'Monitors sales, stock and staff, and signs off approvals. Cannot delete or write off.',
                'grant' => fn (string $p) => ! self::platformOnly($p)
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
                    'inventory.products.view',
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
                ], true),
            ],

            'Sales Executive' => [
                'description' => 'Customer management, quotations, orders and follow-up. No finance edits.',
                'grant' => fn (string $p) => str_starts_with($p, 'crm.')
                    || str_starts_with($p, 'sales.orders.')
                    || in_array($p, [
                        'dashboard.overview.view',
                        'inventory.products.view',
                        'sales.invoices.view', 'sales.invoices.create', 'sales.invoices.print',
                        'sales.coupons.view',
                        'reports.sales_report.view', 'reports.customer_report.view',
                        'reports.dues_report.view',
                    ], true),
            ],

            'Accountant' => [
                'description' => 'Payments, dues and financial reports, including write-offs.',
                'grant' => fn (string $p) => str_starts_with($p, 'finance.')
                    || str_starts_with($p, 'reports.')
                    || str_starts_with($p, 'crm.ledger.')
                    || str_starts_with($p, 'crm.dues.')
                    || str_starts_with($p, 'crm.reminders.')
                    || in_array($p, [
                        'dashboard.overview.view',
                        'crm.customers.view', 'crm.customers.export',
                        'sales.invoices.view', 'sales.invoices.export', 'sales.invoices.print',
                    ], true),
            ],

            'Employee' => [
                'description' => 'Create and edit operational records. Cannot approve, delete or override.',
                'grant' => fn (string $p) => ! self::platformOnly($p)
                    && ! preg_match(self::ELEVATED, $p),
            ],

            'Auditor' => [
                'description' => 'Read-only across the system, including reports and logs.',
                // Read-only, and still not the platform's own marketing site.
                'grant' => fn (string $p) => ! self::platformOnly($p)
                    && (bool) preg_match(self::READ_ONLY, $p),
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
        return str_ends_with($permission, '.manage');
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

            foreach ($this->definitions() as $roleName => $definition) {
                $role = Role::firstOrCreate(
                    ['name' => $roleName, 'guard_name' => $guard]
                );

                $role->forceFill(['key' => self::ACCOUNTS_KEYS[$roleName] ?? Str::slug($roleName, '_')])->save();

                if ($definition['grant'] === null) {
                    continue;
                }

                $granted = $names
                    ->filter($definition['grant'])
                    ->reject(fn (string $p) => $this->isPlatformOnly($p) && ! in_array($roleName, self::PLATFORM_ROLES, true))
                    ->values()
                    ->all();

                $role->syncPermissions($granted);
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
