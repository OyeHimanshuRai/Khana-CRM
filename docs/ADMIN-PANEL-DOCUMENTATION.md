# Khana CRM / Restaurant ERP Admin Panel

## 1. Scope and implementation summary

This document describes the Laravel application in this repository as it is
implemented, not as a generic Laravel tutorial.

The application is a multi-tenant restaurant POS and ERP platform. The admin
panel is the back office for:

- tenant, outlet and user administration;
- restaurant catalogue and menu management;
- point of sale, invoices, payments and cash registers;
- purchasing, suppliers, goods receipts and returns;
- stock, batches, transfers, wastage and recipes;
- tables, QR ordering, reservations, kitchen display and live orders;
- customers, loyalty, coupons, reminders and campaigns;
- online storefront orders and online payments;
- reports, audit/activity logs, email logs and system health;
- marketing CMS content, landing pages and integrations.

The codebase also contains a per-outlet customer storefront and a small
Sanctum API for menu, table and order integrations.

## 2. Technology and repository layout

| Area | Implementation |
|---|---|
| Framework | Laravel 12 |
| Runtime | PHP 8.2 or newer |
| Database | SQLite by default; MySQL/MariaDB/PostgreSQL configuration is supported by Laravel migrations |
| Authentication | `web` guard for staff/admin users, `customer` guard for storefront customers |
| Authorization | Spatie Laravel Permission plus a Super Admin gate override |
| API authentication | Laravel Sanctum personal access tokens with abilities |
| Frontend | Blade templates, Vite, project CSS/JavaScript assets |
| Queue | Database queue by default |
| Broadcasting | Disabled by default; Laravel Reverb is supported |
| Files | Laravel filesystem with public storage links for uploaded media |
| Messaging | Configurable mail, SMS, WhatsApp, push and payment gateway adapters |

Important directories:

| Directory | Responsibility |
|---|---|
| `app/Http/Controllers/Admin` | Admin panel controllers |
| `app/Http/Controllers/Shop` | Customer storefront controllers |
| `app/Http/Controllers/Api` | Sanctum API controllers |
| `app/Services` | Transactional business rules and domain workflows |
| `app/Models` | Eloquent models and relationships |
| `app/Models/Concerns` | Tenant/shop global scopes and reusable model behavior |
| `app/Http/Middleware` | Authentication, tenancy, subscription and module gates |
| `database/migrations` | Schema history, including restaurant-specific additions |
| `database/seeders` | Initial admin, permissions, tenant, shop and catalogue data |
| `resources/views/admin` | Admin layout and module screens |
| `resources/views/shop` | Customer storefront screens |
| `routes/web.php` | Public, table-order and admin web routes |
| `routes/shop.php` | `/shop/{shop:slug}` storefront routes |
| `routes/api.php` | Sanctum API routes |
| `routes/console.php` | Scheduled commands |

## 3. Request entry points and route map

### Public and marketing

`GET /` renders the landing page from configurable company and CMS content.
The application also exposes generated `robots.txt` and `sitemap.xml`, a
rate-limited demo-request form, and public signup paths.

### Admin panel

Admin routes are grouped under the `/admin` prefix and use the `admin.*` route
name prefix. The authenticated group is protected by the staff session,
administrator check, tenant activity, shop subscription, module and
permission middleware as appropriate.

Representative route families include:

- `/admin/dashboard`
- `/admin/pos`
- `/admin/products`, `/admin/categories`, `/admin/brands`, `/admin/modifiers`
- `/admin/invoices`, `/admin/payments`, `/admin/registers`
- `/admin/purchase-orders`, `/admin/goods-receipts`, `/admin/purchase-returns`
- `/admin/stock`, `/admin/stock-adjustments`, `/admin/stock-transfers`
- `/admin/tables`, `/admin/table-qrs`, `/admin/kitchen`, `/admin/live-orders`
- `/admin/customers`, `/admin/suppliers`, `/admin/loyalty`, `/admin/campaigns`
- `/admin/reports`
- `/admin/users`, `/admin/roles`, `/admin/tenants`, `/admin/shops`
- `/admin/settings`, `/admin/integrations`, `/admin/system-health`

The exact route behavior and permission names are defined in
[routes/web.php](../routes/web.php). This file contains more than simple CRUD:
many write actions are individually gated, such as invoice cancellation,
stock approval, payment/refund actions and order status transitions.

### Customer storefront

Storefront URLs are outlet-specific:

```text
/shop/{shop-slug}/
/shop/{shop-slug}/catalog
/shop/{shop-slug}/product/{product-slug}
/shop/{shop-slug}/cart
/shop/{shop-slug}/checkout
/shop/{shop-slug}/orders
/shop/{shop-slug}/account
```

The `storefront.shop` middleware resolves the outlet and prevents inactive
outlets from being rendered. Customer authentication uses the separate
`customer` guard and `customer.shop` ensures that the signed-in customer
belongs to the outlet in the URL.

### Table QR ordering

The public table flow is separate from the staff session. A table QR opens a
short table path, creates or verifies a table session, supports OTP
confirmation where configured, and allows menu browsing, cart operations,
orders, feedback and table payments. The current implementation is in the
top-level table controllers and the table services.

### API

The API is intentionally small and is protected by `auth:sanctum` and a
token-based `throttle:api` limiter:

| Ability | Endpoints |
|---|---|
| `menu:read` | `GET /api/menu`, `GET /api/menu/{product}` |
| `menu:write` | `PUT /api/menu/{product}/availability` |
| `tables:read` | `GET /api/tables` |
| `orders:read` | `GET /api/orders`, `GET /api/orders/{order}` |
| `orders:write` | `POST /api/orders`, `PUT /api/orders/{order}/status` |
| Token identity | `GET /api/me` |

Tokens inherit the user’s current shop context; the shop ID is deliberately
not accepted from the caller in the API URL.

## 4. Tenancy, shop context and authorization

### Tenant hierarchy

The data hierarchy is:

```text
Tenant (business/company)
  └── Shop (restaurant outlet/branch)
        ├── users through shop_user
        ├── products, stock, sales and purchases
        └── tables, kitchen and dining records
```

`CurrentTenant` resolves the active business and `CurrentShop` resolves the
active outlet. Staff can switch tenant/shop context through the admin
switchers. Super Admin is the deliberate exception and can inspect
consolidated data.

Models using `BelongsToShop` receive a global shop scope. Code that genuinely
needs cross-shop reporting or console processing must explicitly use
`allShops()` or `forShop($shopId)`. Jobs and scheduled commands do not have a
browser session, so tenant-specific work must always provide that context
explicitly.

### Middleware gates

The normal admin request pipeline combines:

1. staff authentication (`auth`);
2. `EnsureUserIsAdmin`;
3. tenant activity/subscription checks;
4. shop subscription check where an outlet is selected;
5. `EnsureModuleIsEnabled`;
6. Spatie permission middleware for the action.

`EnsureTenantIsActive` signs out non-Super-Admin users when their business is
suspended or its usable subscription has ended. `EnsureShopIsSubscribed`
blocks only the affected outlet and allows the user to switch to another
outlet. Past-due subscriptions remain usable during their grace period.

Permission checks are also exposed to Blade through `@allows` and
`@anyallows`. Hiding a sidebar item is not the security boundary; the same
permission is enforced by the route middleware.

## 5. Functional module reference

### Platform and administration

- **Dashboard:** operational summaries and shortcuts.
- **Tenants:** business lifecycle, active/suspended state and tenant switching.
- **Shops:** outlets, shop settings, modules, subscriptions and shop switching.
- **Users and roles:** staff accounts, roles, permissions, active state and
  session/security history.
- **Activity and alerts:** audit trail, unread alerts, low-stock and operational
  notifications.
- **Billing/plans:** plan catalogue, subscriptions and subscription payments.
- **System health:** environment and integration diagnostics.

### Catalogue and menu

Products support categories, brands, units, tax rates, images, variants,
modifiers, price lists, batches and shop assignment. Menu import is available
through the menu/import workflow. The restaurant conversion is represented by
the migrations that add menu, variant, modifier and kitchen relationships.

### POS, sales and finance

The POS supports parked sales, barcode/scanner workflows, discounts, tax-aware
invoice calculation, payments, receipts, cancellation and printing. Cash
registers track opening/closing activity. Online payments have intents,
settlements, refunds and webhook handling.

Invoices are not deleted. Cancellation reverses stock, ledger and payment
effects while retaining the document number and audit history. Payment edits
are represented by reversing records rather than mutating historical books.

### Purchasing and inventory

Purchasing includes purchase orders, goods receipts, landed costs, supplier
ledgers, purchase returns and supplier balances. Inventory includes warehouses,
batches, stock movements, adjustments, transfers, wastage and recipe
consumption.

`StockService` is the write boundary for stock quantities and movement ledger
rows. `PurchaseService`, `InvoiceService`, `LedgerService` and
`SupplierLedgerService` coordinate transactional updates and row locks for
the corresponding invariants.

### Restaurant operations

- floors, tables and table QR codes;
- table sessions, table cart items and table bills;
- reservations and customer feedback;
- kitchen stations, KOT tickets and kitchen board/recall screens;
- live orders and order status logs;
- printers and print jobs;
- recipes and dish consumption rather than direct stock sale.

### CRM and customer engagement

Customers have authentication, addresses, carts, wishlists, coupons,
redemptions, loyalty programs/transactions, reminders, campaigns and
support-ticket relationships. Email templates and email logs are available
to administrators. SMS and WhatsApp are adapter-based and default to logging
instead of sending.

### CMS and marketing

Company settings, sliders, services, FAQs, blogs, reels, Instagram posts,
events, collections, showcases, testimonials, landing statistics and SEO
content are managed by the admin CMS controllers and Blade views.

## 6. Core data and business invariants

The most important rules implemented by the service layer are:

| Invariant | Main service |
|---|---|
| Stock balance equals movement ledger | `StockService` |
| Customer balance equals last customer ledger balance | `LedgerService` |
| Supplier balance equals last supplier ledger balance | `SupplierLedgerService` |
| Invoice totals, stock and customer ledger commit together | `InvoiceService` |
| Receipt stock, batches, costs and supplier bill commit together | `PurchaseService` |
| Order/cart/table workflows remain shop-scoped | `OrderService`, `CartService`, table services |
| Tax and cost snapshots remain stable after an invoice is posted | invoice service/model workflow |

Money is rounded to two decimals during calculation, with final round-off
stored on the invoice. Tax mode, tax rates, tax split and cost basis are
snapshotted on invoice lines so later master-data changes do not rewrite
historical documents.

## 7. Scheduled commands and operations

The project provides these application commands:

| Command | Purpose |
|---|---|
| `reminders:schedule` | Create due payment reminder work |
| `reminders:dispatch` | Send due reminders |
| `alerts:sweep` | Generate low-stock, day-close and stuck-table alerts |
| `campaigns:send` | Send due campaign batches |
| `subscriptions:sweep` | Mark lapsed tenants and report grace-period accounts |
| `backup:run` | Dump and prune database backups |
| `email:prune-logs` | Remove old outbound email log rows |
| `permissions:sync` | Synchronize configured permissions |
| `permission:show` | Display roles and permissions |
| `push:keys` | Generate VAPID keys for web push |
| `dine:tidy` | Detect or clear stale dining/table state |

The schedule is defined in [routes/console.php](../routes/console.php). Queue
workers are required for database-queued mail, notifications and other
background work:

```powershell
php artisan queue:work
```

For live kitchen/order updates, configure Reverb and run:

```powershell
php artisan reverb:start
```

Polling remains the fallback when broadcasting is disabled.

## 8. Installation and local setup

Requirements:

- PHP 8.2+ with the extensions required by Laravel and the selected database;
- Composer;
- Node.js/npm;
- a writable `storage` and `bootstrap/cache` directory;
- SQLite for the default local setup, or a configured relational database.

Typical setup:

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed
php artisan storage:link
npm install
npm run build
php artisan serve
```

The repository's Composer `setup` script performs the main install, key,
migration and frontend build steps. `DatabaseSeeder` calls the admin user,
roles/permissions, company settings, tenant, plans, shops, catalogue and
expense-category seeders in dependency order.

For development with the configured concurrent processes:

```powershell
composer run dev
```

### Required production changes

Do not deploy the example defaults unchanged:

- set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` and a strong
  `APP_KEY`;
- configure a production database, cache/session/queue backend and mailer;
- configure `FILESYSTEM_DISK` and run `storage:link` where public media is used;
- configure payment, SMS, WhatsApp, push and Reverb credentials only when the
  corresponding feature is enabled;
- run migrations with `--force`, build frontend assets and configure a
  scheduler/queue worker;
- restrict access to `.env`, logs, backups and uploaded files.

## 9. Configuration reference

The important environment groups are:

| Variables | Used for |
|---|---|
| `APP_*` | application URL, environment, encryption and debug mode |
| `DB_*` | database connection |
| `SESSION_*`, `CACHE_STORE`, `QUEUE_CONNECTION` | state and background processing |
| `MAIL_*` | default mail transport |
| `BROADCAST_CONNECTION`, `REVERB_*` | real-time updates |
| `SMS_*` | SMS gateway adapter |
| `WHATSAPP_*` | WhatsApp adapter |
| `PAYMENT_*` | payment gateway selection and credentials |
| `VAPID_*` / push settings | browser push notifications |

The default local profile deliberately uses SQLite, database sessions/cache/
queue, log mail, log SMS, log WhatsApp and no broadcasting. This permits the
flows to be exercised without external provider accounts.

## 10. Testing and code-level review status

The repository contains a substantial Feature suite covering authentication,
tenant/shop isolation, catalogue, POS, payments, inventory, purchasing,
restaurant dining, storefront, API, subscriptions, reporting and security
flows, plus a Unit example test.

Run the full suite with:

```powershell
php artisan test
```

Useful focused commands:

```powershell
php artisan test --filter=TenantIsolationTest
php artisan test --filter=ShopIsolationTest
php artisan test --filter=CheckoutTest
php artisan test --filter=StockServiceTest
php artisan test --filter=UserSecurityTest
```

The project currently has all migrations applied in the checked local
database. The authoritative schema is the ordered migration history, not the
checked-in SQLite data file.

### Review conclusions

The code-level structure is coherent for the stated product:

- business rules are concentrated in services rather than duplicated in
  controllers;
- tenant/shop isolation is enforced in models and middleware, not only in
  navigation;
- staff and customer authentication are separated;
- API tokens are ability-limited and token-rate-limited;
- financial and stock history is reversed/audited instead of deleted;
- subscription and module checks distinguish tenant-wide and outlet-wide
  failures;
- the test suite reflects the major risk areas, especially isolation and
  financial workflows.

Operational risks to address before production are deployment concerns rather
than missing admin screens: replace local log adapters, set real provider
credentials, configure workers and scheduler, secure backup retention,
disable debug output, and verify the selected database driver against the
full migration suite.

## 11. Extension guidelines

When adding a module:

1. add the migration and model relationships;
2. decide explicitly whether the model is tenant- or shop-scoped;
3. add a service when the operation changes stock, money, ledgers or order
   state;
4. add permissions in the permission configuration and sync them;
5. add module ownership in the module catalogue if it is shop-toggleable;
6. add routes with explicit permission middleware;
7. add Blade screens under `resources/views/admin`;
8. add feature tests for authorization, tenant/shop isolation and the main
   workflow;
9. add scheduled work to `routes/console.php` only when it is safe to run
   without browser context;
10. update this document and [docs/ERP-OVERVIEW.md](ERP-OVERVIEW.md).

