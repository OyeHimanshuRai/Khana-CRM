# Multi-Tenant Retail Platform — how it is put together

This is the working note for whoever picks the codebase up next. It is not a
feature list; it is the set of decisions that are hard to infer from the code
and expensive to get wrong later.

The retail, purchasing, CRM and stock machinery underneath it was written
against an earlier agri-retail SRS and then a jewellery one, and kept through
both: the trade differs, the invariants do not.

**The jewellery, manufacturing/karigar, gold-loan, HR and newsletter modules
were removed** when the platform was re-scoped to *Restaurant POS & QR
Ordering* (see the requirements document of 15 September 2026). What is left is
the foundation that scope stands on — tenancy, roles, catalogue, stock,
purchasing, billing, payments, CRM and reports — plus the CMS, which was kept
deliberately. `git` will not tell you what went: there is no repository, and
the removal is recorded in section 10 instead.

Sections 1–8 are those foundations and are still exactly true. Section 12 is
what multi-tenancy and module gating add on top, and section 13 is the first
restaurant-specific half to be built.

---

## 1. The one rule everything rests on

**Every operational record belongs to exactly one shop, and nothing reads
across that line by accident.**

`App\Models\Concerns\BelongsToShop` adds a global scope to every tenant-owned
model. The failure mode is deliberately an *empty list*, never another shop's
data — forget to filter and you see nothing, not somebody else's takings.

Which shops a person may reach is **data, not a permission**: rows on the
`shop_user` pivot. A permission answers "may they do this at all"; the pivot
answers "to whose data". Super Admin is the single exception and bypasses the
pivot.

Crossing the boundary deliberately is possible and ugly to type, on purpose:

```php
Invoice::allShops()          // genuinely cross-tenant: reports, cron, jobs
Invoice::forShop($shopId)    // one named shop, whatever is selected
```

`App\Support\CurrentShop` is the only thing that decides which shop a request
is in. Two columns carry the answer, because one could not:

| column | meaning |
|---|---|
| `users.current_shop_id` | the shop selected, or NULL |
| `users.all_shops_view`  | whether that NULL means "All shops" or "never chose" |

Without the flag, a brand-new cashier and someone who deliberately picked the
consolidated view are indistinguishable, and one of them ends up in the wrong
place. In All-shops mode the scope still narrows to the reader's own shops —
the consolidated view is not a way around the pivot.

There is no authenticated user in console and queue context, so the scope
steps aside there entirely. Anything tenant-specific in a job must say so with
`forShop()`.

---

## 2. Services own the invariants

Five things in this system must always be true. Each has exactly one door, and
nothing else writes to the tables behind it.

| Invariant | Door |
|---|---|
| `product_stocks` == Σ `stock_movements`, and no quantity moves without a ledger row | `StockService` |
| `customers.balance` == last ledger entry's `balance_after` | `LedgerService` |
| `suppliers.balance` == last supplier-ledger entry | `SupplierLedgerService` |
| An invoice's totals, stock and ledger entries are written together or not at all | `InvoiceService` |
| A receipt's stock, batches, costs and supplier bill land together or not at all | `PurchaseService` |

Every one of them takes a row lock and runs in a transaction. Two cashiers
billing the same customer in the same second is an ordinary Saturday, not an
edge case.

`LedgerService::reconcile()` and `SupplierLedgerService::reconcile()` exist to
*prove* the caches are right, and to repair them if a crashed import ever left
them wrong.

---

## 3. Money: the decisions

**Rounding.** Money to 2 decimals at every step; the grand total rounded to the
nearest rupee with the difference kept as `round_off`, so the arithmetic on the
paper still adds up.

**Tax.** Whether a sale is CGST+SGST or IGST is decided *once*, at billing
time, from the two addresses as they stand — and then stored on the invoice. A
later address edit must not restate tax on an invoice already filed. Line items
copy the rate and the split rather than pointing at `tax_rates`, for the same
reason.

**Tax-inclusive prices.** A shelf price containing GST is stripped back to its
taxable part before tax is applied, or the tax is charged twice. The POS
overrides the product's own convention per line and tells the service so
(`price_includes_tax`), because the counter works entirely in what the customer
hands over.

**Invoice-level discount** is apportioned across the lines pro-rata by taxable
value, not subtracted from the total. Subtracting it would leave the tax
overstated and the GST return disagreeing with the invoice. This is the single
most commonly wrong thing in billing software.

**Cost basis** is the weighted average captured *at the moment of sale*, copied
onto the invoice line. A later, dearer purchase must not change the margin on a
month that has already been reported.

**Landed cost.** Freight and other charges on a goods receipt are spread across
the lines by value and folded into what the stock is carried at. Valuing stock
at the invoice line alone quietly overstates every margin the shop reports.
Free stock counts as stock and pulls the average down — correctly, because the
shop did get more sellable units for the same money.

---

## 4. Nothing is deleted; things are reversed

| Instead of | The system does | Why |
|---|---|---|
| Deleting an invoice | Cancels it: stock back, ledger reversed, payments voided, number kept in the series | SRS §28, and an audit needs the gap explained |
| Editing a payment | Records a reversing payment pointing at the original | "The amount changed" is not something a shop's books should be able to say |
| Editing a stock quantity | Another `stock_movement` | "Why is there 3 kg less than yesterday" stays answerable |
| Deleting a stocked product | Refused until the stock is adjusted out | Withdrawing something physically on a shelf hides it from the count, not from sale |

A cheque is the reason `payments.status` exists: taken today, cleared next
week, and sometimes not at all. Only `cleared` payments reduce a balance.

---

## 5. Stock

`StockService` is the only writer. Its public surface:

- `receive()` / `issue()` — inward and outward, both signed into the ledger
- `adjustTo()` — corrects to a **counted** quantity, not a delta, because that
  is what a stock count produces; a reason is mandatory
- `transfer()` — two ledger rows, not one: the sending shop is short and the
  receiving shop is long, and each reads its own side
- `reserve()` / `release()` — holds for unpicked online orders; not a movement,
  so no ledger row
- `planPick()` — returns a FEFO plan without executing it, so the caller can
  price each line at its own batch cost first

**Oversell is refused inside the lock**, unless the shop has turned
`allow_negative_stock` on. **Expired batches are skipped** when
`block_expired_sale` is on — both are per-shop settings, per the SRS.

`product_stocks` uniqueness survives a NULL `batch_id` through a generated
column on MySQL/MariaDB and an expression index on SQLite/Postgres — see the
migration. Without it, "the un-batched row for this product" could be created
twice under concurrency and `StockService` would lock one of two halves of the
same quantity.

Stock transfers are **two-step** (dispatch, then receive) because the goods
really are in neither warehouse while they are on the van, and a one-step move
hides exactly the shortfall a transfer document exists to surface. A short
receipt is recorded and logged loudly.

---

## 6. The reminder engine

Two halves, deliberately separate:

- `reminders:schedule` (daily, 08:30) works out what should be said and writes
  it down
- `reminders:dispatch` (every 30 min, 09:00–19:00) says it

Splitting them means a scheduling run cannot be delayed by a slow mail server,
a mail outage loses nothing, and the shop can see what is about to go out.

**Both are idempotent.** Scheduling relies on a unique index on
`(invoice_id, trigger, channel)` rather than a "have I done this" query, so
running it twice — or after a crash halfway through — cannot double-send. A
shop whose reminders arrive twice gets marked as spam, and then none arrive.

**Every reason to stay quiet is checked at send time, not schedule time.** A
customer who paid yesterday is not chased today. Nothing damages a shop's
relationship with a farmer faster.

Triggers, offsets and quiet hours all live in `config/reminders.php`. SMS and
WhatsApp are declared but off — the scheduler will not queue what it cannot
deliver, and a row claiming to have sent an SMS nobody sent is worse than an
honest skip.

---

## 7. Permissions

`config/permissions.php` is the single source of truth. The seeder, the role
matrix UI, the sidebar filter and the route guards are all generated from it.
Adding a capability is one line plus `php artisan permissions:sync`.

321 permissions across 11 roles. Beyond the usual verbs, the SRS's elevated
rights are separately grantable and audited:

`discount` · `credit` · `refund` · `adjust` · `write_off` · `cancel`

A Cashier gets the terminal and nothing that lets them rewrite what they
billed. `write_off` is the bluntest right in the system — money owed stops
being owed and nobody paid it — so it has its own permission, its own ledger
type, and its own confirmation screen.

Route guards are the outer defence; views hide controls the user cannot use;
controllers re-check anything destructive. Two endpoints are authorised inside
their controller rather than by middleware, because the right they need depends
on what was submitted: the POS (discount/credit) and the payment bulk actions.

---

## 8. Front end

No build step. Plain CSS and vanilla JS under `public/assets`, cache-busted by
`filemtime()`.

| File | What it owns |
|---|---|
| `app.js` | toasts, `data-ajax` form submission, the JSON envelope |
| `modal.js` | `data-modal` screens |
| `ajax-list.js` | `data-ajax-list` filtering without a reload |
| `line-items.js` | the repeating-rows control behind every document |
| `warehouse-lines.js` | warehouse-scoped lookups (adjustments, transfers) |
| `customer-picker.js` | the customer type-ahead, **delegated** so it works inside modals |
| `pos.js` | the counter: tender split, change, credit warnings, keyboard |

**No `<script>` may live in a modal fragment** — markup injected via
`innerHTML` never runs its scripts. Anything a modal needs must be delegated
from a globally loaded file. This is why `customer-picker.js` exists separately
from `pos.js`.

Blade comments must close with `--}}`, not `-}}`. A single dash does not
terminate the comment and Blade silently swallows everything to the next real
terminator. This cost a debugging round; it is written down so it costs nobody
else one.

---

## 9. Departures from the SRS

| SRS says | Built as | Why |
|---|---|---|
| "Bootstrap/modern responsive UI" | The project's existing custom design system | Rewriting a working UI to Bootstrap would destroy it for no functional gain |
| `purchases / purchase_items` plus a separate purchase invoice | One `goods_receipts` document carrying the supplier's bill | The bill comes with the goods; two documents to reconcile where the trade has one piece of paper is friction, not rigour |
| Products global *or* shop-specific | Global catalogue, per-shop price/stock overrides | The alternative makes a group-wide item report impossible and forces the same item to be re-created at every branch |
| "Export to Excel and PDF" | CSV export everywhere; print-to-PDF via the browser | CSV opens in Excel and needs no dependency; a server-rendered PDF is a font-embedding problem for something every browser already does |
| SMS / WhatsApp notifications | In-app alerts and email only; SMS/WhatsApp declared, disabled, honestly skipped in the log | No provider is wired; pretending otherwise would put lies in the audit trail |
| `tenant_id` on every operational table (SRS 6) | `tenant_id` on `shops`; every operational row carries `shop_id` | Same guarantee through one join instead of a column forty tables would have to remember to filter on. See the `tenants` migration |

---

## 10. What is not built

Stated plainly rather than left to be discovered.

**Removed on 15 September 2026**, when the platform was re-scoped to restaurant
POS and QR ordering. Code, routes, views, migrations and tables all went; the
tables are dropped by `2026_09_15_000000_drop_removed_module_tables`, which is
guarded so it is a no-op on a fresh database.

- **Jewellery** — rate board, metal ledger, metal balances, old-gold purchase,
  stone types and the per-product stone lines. `InvoiceService` priced a
  rate-based line and posted its weight to the metal ledger; both paths are
  gone and an invoice line is now priced the ordinary way.
- **Manufacturing / karigar** — job orders, material issue, karigar returns and
  the karigar ledger.
- **Gold loan** — loan accounts, pledges, loan transactions, and the customer
  KYC columns that existed to support pledging.
- **HR** — employees, departments, attendance, leave and the payroll report.
  Note that *Employee / Cashier Sales* survives: it reads `invoices.created_by`
  and never needed an employee record.
- **Newsletter** — subscribers, campaigns, open/click tracking and the public
  subscribe/unsubscribe routes. Email **templates and delivery logs were
  kept**: they are how transactional mail is authored and audited, and
  `EmailTemplateService` now renders a template directly instead of dressing it
  up as a campaign.

**Not built, and never was:**

- **Chart of accounts** — accounting integration was always a future item. It
  is the one entry left in `config/menu.php` with no route, so it renders as a
  `data-soon` placeholder rather than a link. The SRS does not ask for it;
  §4's Finance section stops at taxes.

**Built since, for the restaurant scope:** dining areas, tables, the floor
plan, the per-table QR code and the scan flow that opens a table session
(section 13); the menu itself — sizes, add-ons, veg/non-veg marks, per-channel
prices, serving windows and the sold-out tap (section 14); the guest's menu,
cart and order (section 15); the kitchen — stations, KOT routing, the display,
the timers and the KOT slip (section 16); settling the table — the bill, the
split, merge and move, and the walkout (section 17); and recipes — what a dish
is made of, what it costs, and the ingredients that leave the store when it is
cooked (section 18); wastage, and bulk menu import (section 19).

**Built since this section was last written** (it had said these three were
outstanding, and they are not): online payment with gateway webhooks, refunds
and a settlement screen; the live-orders board for counter staff, separate from
the kitchen's; subscription plans and billing; SMS and WhatsApp delivery, which
now have real gateways behind `config/sms.php` and `config/whatsapp.php` rather
than only a log driver; the support desk (section 20); and the system-health
screen with nightly database backups (section 21).

**Still not built, and nothing in the sidebar pretends otherwise:** the chart
of accounts above, and the §21 "future" list — captain app, kiosk, TV menu
boards, offline POS and aggregator integrations. Those are named as future work
in the SRS itself.

A restaurant now runs end to end. A guest scans the sticker and orders from
their phone, or a captain takes it on a tablet; the ticket lands routed on the
station that cooks it; the kitchen bumps it, and the flour and butter come off
the shelf as it does; the counter settles the table — in one bill or several —
and frees it for the next party. Sales, GST, the ledger, the food cost and the
stock all move from that one sequence.

---

## 11. Running it

```bash
php artisan migrate --seed        # first run
php artisan permissions:sync      # after editing config/permissions.php
php artisan schedule:work         # reminders and alerts
php artisan queue:listen          # email
php artisan alerts:sweep --prune  # raise SRS 15 notifications by hand
```

Tests: `php artisan test`. **Do not run `view:clear` or other artisan commands
while the suite is running** — Windows file locking makes compiled views fail
to rename mid-run and produces failures that are not real.

`APP_URL` carries a sub-directory on this install, so every feature test calls
`URL::forceRootUrl('http://localhost')` in `setUp()`. New test classes need the
same line or every request 404s.

---

## 12. Tenancy, modules and notifications

### 12.1 Two tiers, not one

`shops` used to be the top of the tree. A **tenant** — one business, which may
run several branches — sits above it, and a shop became a branch of one.

| | decides | resolved by |
|---|---|---|
| `tenants` | the legal entity: name, GSTIN, PAN, logo | `App\Support\CurrentTenant` |
| `shops` | the branch: address, invoice series, stock | `App\Support\CurrentShop` |

Isolation is still enforced one tier down. Every operational row carries
`shop_id`, every shop carries `tenant_id`, and a user can only reach shops
inside their own tenant — so the tenant check stands in front of forty tables
that already filter correctly, rather than being a forty-first column nobody
would remember.

The asymmetry between the two switchers is deliberate:

- A **Super Admin** belongs to no tenant, may reach every one, and may sit in
  the consolidated view. `CurrentTenant::set()` clears the shop when the
  company changes — a branch of the company you just left is not a branch you
  are still standing in.
- **Everyone else** belongs to exactly one tenant and has nothing to switch to,
  so the tenant switcher does not render for them at all.
- A **suspended** tenant keeps every row it ever wrote and its staff cannot
  sign in (`EnsureTenantIsActive`). A Super Admin can still open it, because
  the reason to open a suspended account is to fix why it was suspended.
  `TenantController::destroy` refuses while branches exist and says so.

### 12.2 A shop switches its own lines of business on

Not every branch does every trade. A counter that only takes orders has no use
for purchase orders; a central kitchen has no till. So a shop picks from
`config/modules.php` when it is set up, and what it picks decides what its
dashboard, its sidebar and its URLs offer.

**This is not a second permission system.** The two answer different questions
and both have to say yes:

| | asks |
|---|---|
| module | does this shop do this kind of business at all? |
| permission | may this person do it? |

A shop that does no purchasing hides it from everybody, its owner included. A
shop that does still hides it from a cashier, because the cashier has no
permission. Collapsing them would mean either "turn it off for the shop" leaks
to whoever holds the right, or "grant the right" quietly opens a line of
business.

A prefix may be claimed by more than one module, and is then reachable while
any one of its owners is on. No shipped module overlaps another today — the
mechanism outlived the case that needed it, and `ShopModuleTest` declares its
own overlap rather than pretending config still has one.

Three things are worth knowing about how it is wired:

**The route guard reads the route's own permission.**
`EnsureModuleIsEnabled` is applied once, to the whole admin group, and works
out what a route needs from the `permission:` middleware the route already
declares — `config/modules.php` says which permissions belong to which module.
Nothing is wired per route, and a route cannot end up permission-guarded but
module-open, which is exactly the gap somebody would find later.

**A permission no module claims is never gated.** The dashboard, settings,
users, content and marketing are platform, not a trade a shop opts into. That
default is also what stops a permission added tomorrow from going dark because
nobody remembered to list it.

**`null` and `[]` are different answers.** Null means the shop has never been
asked and everything is on — every branch that existed before the feature is
in that state, and taking them dark on upgrade would be the worst possible
reading of "not configured". An empty array means somebody was asked and
unticked every box, and is honoured as the answer it is.

In the consolidated all-shops view a module is on if **any** reachable branch
runs it. The intersection would blank the consolidated view for any group
whose branches differ, which is every group worth consolidating.

### 12.3 Notifications (SRS 15)

`alerts`, not Laravel's `notifications` table — a uuid key and an untyped JSON
payload cannot be filtered by shop, grouped by kind or deduplicated across a
scheduler run, and SRS 15 needs all three.

Two ways an alert is addressed, and both are real: `user_id` for one person,
or a `can` permission for whoever holds it at that branch. SRS 15's own table
is written in roles — "Low stock → Branch Admin / Warehouse" — and addressing
that to whoever happened to be on shift would hide it from whoever came in
next.

| raised by | what |
|---|---|
| `alerts:sweep` (07:45, 15:00) | low stock, tills left open past their own day |
| the service that did the thing | invoice raised, payment received |
| `LoginRequest` | an account locked out after five failures |

Every one is **idempotent by construction**: a unique `dedupe_key`, written
through the index. A "have I done this already" query would race with itself;
an index cannot. Running the sweep twice, or resuming after a crash halfway,
updates rather than duplicates. An existing alert also keeps its read state —
somebody who has seen "this item is out of stock" should not have it march
back into their unread count every morning.

The bell reaches **across branches** deliberately. Everywhere else in this
system a shop-scoped default is right; here a manager covering three shops
needs to know one of them is running out of stock without switching into it to
find out, so each row names its branch. The count is server-rendered by a view
composer so the dot is honest before anything is clicked; the list is fetched
on first open (`[data-dropdown-fragment]` in `layout.js`), so an ordinary page
load pays for a count and not ten rows.

---

## 13. The room: areas, tables, QR codes and sittings

### 13.1 Why `restaurant_tables` and not `tables`

The requirements list it as `tables`. "Table" is what every schema tool, every
migration and half the framework already means by the word, and a model called
`Table` next to a database table called `tables` makes every sentence in this
codebase ambiguous to save four characters. The concept is identical.

`floors` kept its own name because nothing else claims it.

### 13.2 Status is stored, not derived

The dashboard counts Available / Occupied / Reserved / Billing / Cleaning, and
three of those five are facts about the room rather than about any order:
nothing in the order tables can tell you a table is being wiped down, or that
it is being held for a booking that has not walked in.

So `restaurant_tables.status` is a column the front of house writes, and it has
its own permission — `dining.tables.adjust`, separate from `dining.tables.edit`.
A captain has to be able to seat a party forty times an evening without also
being able to renumber the restaurant.

The status control therefore sits on the row and on the plan tile itself rather
than behind a modal. A modal at eight on a Saturday is a modal nobody opens,
and a status nobody updates is worse than no status at all.

### 13.3 One live QR per table, and the old one is kept

The invariant, held by `App\Services\TableQrService` and nothing else:

> a table has at most one live QR code at any moment

MySQL cannot express "unique where `revoked_at` is null" as an index, so the
guarantee is a row lock inside a transaction: issuing revokes whatever was live
in the same breath. Two managers pressing Regenerate in the same second is
unlikely and would be very hard to diagnose, which is exactly the kind of bug
worth spending a lock on.

**Revoking is never a delete**, and this is the half worth understanding. A
printed sticker outlives the row it came from. Overwriting a token would leave
every sticker in the restaurant silently resolving to the *new* code; a revoked
row lets a scan of a withdrawn sticker be told apart from one that never
existed, which is the difference between a guest who is confused for five
seconds and a guest who orders against the wrong table.

`table_qrs` is therefore append-only in practice: one row per code ever issued,
each with when it was issued, by whom, when it was withdrawn and why. "Why did
table 7's code change last Tuesday" is a question that gets asked.

### 13.4 The token is the secret, and it does not expire

`/t/{token}` — 32 URL-safe characters, globally unique, no signature and no
expiry.

Signed URLs would be wrong here, and the reason is physical: the URL is printed
on a sticker that lives on a table for months. Anything with an expiry in it
becomes a dead sticker on a date nobody wrote down. The token *is* the secret,
revoking the row is how it is withdrawn, and what a scan opens is a public menu
rather than anybody's data.

Uniqueness is global rather than per shop because the public route resolves a
token with no tenant in context — there is nobody signed in at that point, so
the token has to identify the branch by itself.

### 13.5 Positions are percentages

Floor-plan coordinates are percentages of the canvas at three decimals, not
pixels. The plan is rendered at whatever width the screen gives it, and pixels
from a designer's laptop would put the rooftop through a wall on the tablet a
captain carries.

They are clamped server-side as well as in the browser: `floor-plan.js` is the
usual caller but it is not the only possible one, and a table at 4000% would
simply vanish with no way back except the database.

Dragging saves on drop, not on every frame. A drag across a plan is forty
`pointermove` events, and forty PUTs would be forty rows in the activity log
and a lock convoy on one table — which is also why `setPosition` is the one
write in the admin that deliberately logs nothing.

### 13.6 QR codes are rendered, not stored

`App\Support\QrCode` returns inline SVG through `bacon/bacon-qr-code`.

SVG rather than PNG because the output gets printed, and a raster code sized
for a screen turns into soft edges at 25mm that cheap phone cameras give up on.
It also needs no imagick, which this install does not have.

Nothing is cached. A QR is a pure function of its token and costs about a
millisecond; caching one would mean a file per table to invalidate on every
regeneration — more ways to be wrong than it saves.

The print sheet is a standalone page, not an admin-layout view: a fragment
would carry the sidebar onto the paper. Tables with no live code are left off
it rather than printed blank, because somebody will stick an empty square on a
table.

### 13.7 A sitting is the party, not the furniture

`table_sessions` is what makes two sentences in the requirements mean
something: an order is placed against "the restaurant + outlet + table +
customer/session" (§3.7), and later orders from the same table join the same
bill (§3.11).

Without it, "this table's order" can only mean "every order ever placed at this
table", and the guests who sat down at eight inherit the bill of the guests who
left at seven.

**At most one open session per table**, held the same way the QR invariant is:
a row lock inside a transaction. The lock is taken on `restaurant_tables` and
not on `table_sessions`, because the row being protected is the one that does
not exist yet. Three friends at one table scanning the same sticker in the same
second land in one sitting — otherwise the table gets three bills and the waiter
gets an argument.

A session moves `open → billed → closed`. `billed` is deliberately not live:
once the bill is raised, adding to it silently would have the guest pay for
something the printed total did not include.

Sessions are never deleted. A closed sitting is the history behind a bill.

### 13.8 The status the room shows follows the sitting

Opening a session marks the table `occupied`; closing one marks it `cleaning`
rather than `available`, because a party has just left it and offering it to the
next guests before anybody wiped it down would be lying about the room.

A scan promotes `reserved` and `cleaning` too, and that is the point rather than
an oversight — the party with the booking has arrived, or new guests have sat at
a table just vacated. `billing` is the one exception: a party mid-settlement
re-scanning the sticker must not tell the floor the bill has been cancelled.

Only a *new* session changes the status. A re-scan of an open one changes
nothing, so a status a member of staff set during the sitting survives the guest
reloading their phone.

### 13.9 The scan route is the only public write

`/t/{token}` is the one unauthenticated route in the app that opens a record.
Three things follow, and they shape the whole controller:

**There is no tenant in context.** Nobody is signed in, so the token has to
identify the branch by itself — which is why table QR tokens are globally unique
and why every read in `TableScanController` goes through `allShops()`.

**A withdrawn code is told apart from one that never existed.** A revoked token
redirects to an explanation; a made-up one is a 404. Telling a guest "no such
table" when the real answer is "we reprinted these last week" sends them to
complain about the wrong thing.

**It is throttled**, because it is reachable by anyone with a camera and it
writes. Generous enough that a table of six all scanning at once is fine, tight
enough that a script cannot open ten thousand sittings.

The scan redirects rather than rendering, so the address bar loses the QR token
as soon as it has been used, and the guest's own session token lives in their
cookie — never in a URL. A URL with a session token in it gets screenshotted
into a group chat, and whoever opens it is then ordering onto somebody else's
bill.

### 13.10 Upgrading an existing shop

`2026_09_15_110000_switch_dining_on_for_existing_shops` adds `dining` to every
shop whose `modules` list is non-null.

That looks like it contradicts §12.2's rule that an explicit list is the
operator's answer, and it does not: a branch configured before `dining` existed
was asked about the modules that existed *then*. It cannot have said no to one
that did not. Leaving those shops alone would take the floor plan dark for every
existing branch on upgrade, which reads as the feature being broken rather than
off.

Shops on `null` are untouched — null already means everything, and writing a
list into them would turn "never asked" into "chose this", which is a worse lie
than the one it fixes.

---

## 14. The menu

### 14.1 It is still `products`

The table was not renamed to `menu_items`. It is still a thing sold, stocked,
taxed and reported on; renaming it would touch every service, every report and
every foreign key in the codebase to change a noun.

What changed is what hangs off a row, and what came off it: `crop`,
`pest_disease`, `technical_name`, `dosage` and the two advisory text fields
went with the agri catalogue. A column on `products` is a field on the product
form and a column in every export — it does not stay quietly out of the way.

### 14.2 Null food type is a real answer

`food_type` is nullable, and null does not mean "unknown, assume veg". It means
**this row is not food**: a bottle of water, a paper bag, a service-charge line.
Those must not get a green dot, and defaulting them to veg would put one there.

The dot colours are the Indian convention rather than decoration — green square
for vegetarian, brown for not — and getting it wrong is a complaint, not a
design note. Every screen that prints the mark prints the words beside it:
colour alone is not a label anybody can rely on.

### 14.3 Sizes are absolute prices, and one always opens the menu

`product_variants` carries a price, not a delta. "Full is +140" is how a
spreadsheet thinks; "Half 180, Full 320" is how a menu is written and how a cook
quotes it — and a delta breaks the moment the base price moves, which is every
time costs do.

A dish either has sizes or it does not. Where it does, **an order line must name
one**: there is no "the default size" a cashier can skip past, because that is
how a table gets billed for a Full and served a Half. `is_default` only decides
which one the card opens on.

Exactly one row ends up flagged, and `ProductController::syncVariants` is where
that is held. A form with three boxes ticked is a user who did not realise it
was a radio, so the last one wins; a form with none ticked promotes the first,
because a dish showing no size selected shows no price either.

Sizes are matched **on name** when the form is saved, so editing a price keeps
the row — and with it anything that has counted sales of a "Full".

### 14.4 min/max beats a `type` column

A modifier is a question; `modifier_options` are its answers. The shape of the
question is two integers, and they cover every case a menu has:

| min / max | means |
|---|---|
| 1 / 1 | a required choice — pick your crust |
| 0 / 1 | an optional choice — add a dip? |
| 0 / null | any number of extras — toppings |
| 2 / 2 | exactly two — "choose 2 sides" |

`max_select` null is "no ceiling", which is a different answer from 0 and is why
it is nullable rather than zero-as-unlimited.

`Modifier::accepts()` is the only place the rule is decided, so the cart, the
POS and the API cannot disagree about whether a pizza has a crust. It is checked
server-side when an order line is built, never only in the browser: a cart
posted by hand must not be able to buy a pizza with four crusts.

### 14.5 A question belongs to many dishes

`modifier_product` is a pivot, not a JSON blob on the product, and that is the
whole point: a restaurant defines "Choose your crust" once and ticks six pizzas.
Editing what cheese burst costs then changes it on all six.

Which is also why the attaching is edited on the **add-on** screen rather than
on each dish — that is the direction the work flows. The product form shows what
a dish asks, read-only, with a link.

Deleting a question that dishes still ask is refused rather than cascaded.
Cascading would silently take a required choice off six pizzas, and the next
order for one would be accepted with no crust — a failure nobody would trace
back to here.

### 14.6 Availability is three questions with one answer

`Product::isOrderable()` asks all three — switched on, not sold out, inside its
serving window — because every caller wants the same answer, and a customer menu
that disagreed with the POS about whether breakfast is on would be worse than
either being wrong alone.

Two details worth knowing:

**A window may cross midnight.** A late-night menu running 23:00 to 02:00 is a
real thing, and a plain `from <= now <= to` would serve it for one hour a night.

**Sold out clears itself.** `sold_out_until` is what makes the flag usable: a
kitchen that runs out of prawns at nine wants them back tomorrow, not a note on
the fridge. The flag is **not written back** when it expires — reading is not the
place to write, and a guest's menu page must not fire an UPDATE.

### 14.7 Sold out is one tap

Its own endpoint, first in the row actions, on `inventory.products.edit` rather
than a right of its own. A kitchen that had to open an edit form — or wait for a
manager — would simply keep selling what it has run out of.

Throwing the flag clears any old "back on at" time, or a dish would come back the
moment it was taken off.

### 14.8 Channel prices: null means "the same"

`dine_in_price`, `takeaway_price` and `delivery_price` are all nullable, and null
means *use the shelf price*, never *free*. A restaurant that charges the same
everywhere fills in nothing. A typed zero is a deliberate decision to give it
away on that channel and is kept — which is why the controller uses `??` and not
`?:`.

### 14.9 One repeating-row control, two forms

Sizes and add-on answers are the same control, so they share
`js/modifier-options.js` and one markup contract. The size rows post under
`options[...]` for that reason; the server knows which it is from the endpoint
they arrived at.

The clone token is written `@{{i}}` — Blade's own escape. Writing it as
`{{ '{{i}}' }}` looks like it should work and does not: Blade matches the inner
`}}` as the end of the outer echo and compiles a syntax error.

---

## 15. Ordering from the table

### 15.1 One `orders` table, four channels

A dine-in order is written to the same `orders` the storefront uses, not to a
`table_orders` beside it. §13 wants sales reported across channels and §6 wants
the POS to handle table, takeaway, delivery and counter from one screen; two
tables would make every one of those a UNION somebody has to remember, and the
first report that forgets is wrong in a way nobody notices for a month.

Three columns stopped being universal truths to make room for it:

| column | why it is now nullable |
|---|---|
| `customer_id` | a dine-in guest need not sign in (§14) |
| `payment_method` | a table pays at the end, not when it orders |
| `ship_*` | a table in a dining room is not being shipped anywhere |

The shipping fields are still required for a *delivery* order — `OrderService`
validates them at checkout, which is where a rule that applies to one of four
channels belongs. The column was never the right place for it.

**One status ladder, not two.** The web's and the kitchen's are the same shape
once you stop calling the middle one "packing":

    placed → accepted → preparing → ready → served | delivered

So the column was left alone and the *vocabulary* moved into `Order::STATUSES`.
Existing rows keep their spellings; rewriting live rows would break every
report already run and filed. `Order::KITCHEN_FLOW` is the sequence a ticket
climbs, separate from `STATUSES` because that is a vocabulary and this is an
order of operations.

### 15.2 The cart is not `carts`

`carts` belongs to a customer and lives for weeks — half a wishlist.
`table_cart_items` belongs to a *sitting*, lives for one meal, and carries two
things the storefront cart has no concept of: a chosen size and a set of chosen
add-ons.

One table, not three: the lines hang straight off the session, because a cart
with no items is not a thing worth a row.

**The cart is shared by every phone at the table.** §3.11 says later orders join
the same bill and the session is already shared by everyone who scanned that
sticker; a cart per device would have four phones quietly building four orders
that all land on one bill anyway, with nobody able to see what the others added.
The cost is that a line may appear that somebody opposite added — which is what
people expect of a paper pad.

### 15.3 A cart is a quote; an order is a commitment

The cart stores **ids**. It is re-priced from the live menu every time it is
rendered, so a kitchen that changes what cheese burst costs at six does not owe
a discount to somebody who opened the menu at five.

The order stores **names and prices**. A menu gets re-worded and re-priced, and
a ticket sent at eight has to keep saying what it said — exactly as an invoice
line copies its tax rate rather than pointing at one. The ids are kept alongside
so a report can still group by dish and by add-on while those rows exist, and
`order_item_modifiers.modifier_option_id` is nullable so an option deleted next
season does not take the record of what was eaten with it.

### 15.4 Three rules the browser is not trusted with

All of these are in `TableCartService`, and all of them are ways a hand-posted
form would otherwise get through:

**A size must be named where a dish has sizes.** There is deliberately no
fallback to the default — a caller that did not say which size did not ask the
guest either, and that is how a table gets billed for a Full and served a Half.

**An add-on must belong to a question this dish asks**, and still be available.

**Each question's min/max must be satisfied**, through `Modifier::accepts()` —
the same method the admin screen and the menu card read, so no two of them can
disagree about whether a pizza has a crust.

And one more, at a different moment: **what is sellable is re-checked when the
order is placed**, not only when the line was added. A dish can sell out between
a guest choosing it and tapping Order; the second check is the one that matters,
and it leaves the cart alone so they can remove that line and send the rest.

### 15.5 One ticket per send, one bill per sitting

`TableOrderService::place()` takes a row lock on the session before it reads the
cart. Two phones at one table tapping Order in the same second would otherwise
both read it, both write an order, and the kitchen would cook everything twice.

A sitting may send several orders — that is §3.11, and it is what makes "another
round of drinks" a second ticket for the kitchen and one more line on the same
bill. The order number is the table code and the round: `GF-04/2` is the second
ticket from table 4 and reads as that to everybody who hears it called.

Nothing is reserved. A kitchen cooks to order; reserving a plate of food the way
a warehouse reserves a box would be a fiction. Stock comes off through recipes
when the dish is made, which is inventory work still to come.

No discount is applied per order either. A table's discount is given once, on
the bill, by whoever is authorised — applying it per round would let four rounds
of drinks take four discounts.

### 15.6 The guest's pages work without JavaScript

Not a constraint that was imposed; a decision. The menu runs on whatever phone
somebody walked in with, over restaurant wifi, and a card that needs a bundle to
download before anybody can order fails at the exact moment it matters.

So every form here is a real form with a real action and method, and the server
answers a plain POST with a redirect and a flash. `<details>` gives the
disclosure, radios and checkboxes give the choices, and the server does the
arithmetic.

`table-forms.js` upgrades that when it is there - a few kilobytes, loaded
`defer`, its own engine rather than the admin bundle or the marketing one - so
a dish goes in without the page moving and the answer arrives as a toast styled
in the guest theme. It takes itself out of the way if `fetch` is missing, and
every form it touches still submits natively if it never loads at all. The
order of those two sentences is the whole design: the page works, and then it
gets better.

Two forms on the cart are deliberately left alone. Changing a quantity or
removing a line moves the line total, the order total, the cart bar and whether
"Send to the kitchen" may be pressed - four things derived from the one being
changed. Keeping them honest over fetch means re-rendering the card or
redirecting to the same page, and a redirect to the same page is a reload with
an extra round trip. A screen that toasts "Updated." above a stale total is
lying about money. Checkboxes are used even
for a pick-one question: radios would need a distinct `name` per question, and
the server takes one flat `options[]` list so the count rule is enforced in one
place rather than half of it in the browser's radio behaviour.

The session token lives in the guest's cookie and never in a URL. A URL with one
in it gets screenshotted into a group chat, and whoever opens it is then ordering
onto somebody else's bill.

### 15.7 A dish nobody filed still sells

`MenuService::card()` ends with an "Also available" section holding every
sellable dish that no active category claimed.

Without it a dish with no category — or one whose category was switched off —
would vanish from the card while remaining perfectly sellable, and the
restaurant would lose the sale without ever seeing an error. The section is also
a visible nudge to go and file it.

---

## 16. The kitchen

### 16.1 Why the state lives on the line

One ticket for a table is routinely two jobs in two rooms: the mojito at the
bar and the seekh at the tandoor. A single status on `orders` cannot say "the
drinks are poured and the kebab is still on", so `order_items` grew four
columns — a station, a status, and two stamps — and the order's status became
**derived**: it is the least-advanced of its kitchen lines.

    bar: ready        tandoor: preparing   ->  the ticket is Preparing
    bar: ready        tandoor: ready       ->  the ticket is Ready

The alternative — one status on the order, bumped by whoever gets there first —
means the bar marks a table Ready while the tandoor is still cooking, and a
runner takes half a table's food out. That is the failure the whole design is
arranged around.

The order still holds the number everybody else reads. The guest's phone, the
bill screen and the reports never learn that lines have a status at all.

`kitchen_status` is nullable, and **null means "not the kitchen's problem"**:
every row written before this existed, and every line of a web order for a shop
with the module off. The feed keys off NOT NULL, so nothing historic is dragged
onto the screen on upgrade.

### 16.2 Routing, resolved once

`KitchenRouter` answers "where is this cooked" in one place, because three
screens ask it: the table order, the POS, and the menu screen where somebody is
checking their routing before a Friday service.

| read in this order | why |
|---|---|
| the dish's own station | the deliberate exception |
| its category's | how routing is actually set |
| its category's parent's | so "Main Course to Tandoor" covers the sub-sections under it |
| the shop's default | so nothing is ever cooked by nobody |

Only the last can return null, and only for a branch with no stations at all.
That is read as "this shop does not work in stations" rather than as a
misconfiguration: a one-room kitchen has one screen and never sets any of this
up, and the board still shows it everything.

Setting a station on every one of four hundred dishes is how a restaurant ends
up with half of them routed and nobody noticing, which is why the category is
the normal place and the dish is the exception — and why the station list shows
a count of unrouted dishes rather than waiting for somebody to find them.

**The station is copied onto the line at placement**, exactly as the dish's name
and its price are. Re-filing a dish at nine must not move a ticket already on a
pass, and a preparation-time report run next month has to say which station
actually cooked it, not which one would cook it today.

### 16.3 Why the board polls

Section 9 of the requirements asks for "WebSockets or another real-time
transport". This is the other one: the board re-fetches its own fragment every
seven seconds and swaps it in.

A socket needs a broker running beside PHP, and a broker that dies at eight on a
Saturday takes the kitchen's screen with it in a way nobody notices until the
complaints start — whereas a poll that fails simply retries. The worst case here
is a ticket arriving six seconds late, on a screen that has one job and sits on
the restaurant's own wifi. When this platform grows a broadcast layer, the same
fragment can be pushed instead of pulled and nothing above that line changes.

The poll stops while the tab is hidden, and while a dialog is open or somebody
is inside a control on the board — a recall reason half typed, or a thumb on its
way down to a button that is about to be replaced by a different one.

**What decides whether it rings** is the highest ticket id on the board, not the
number of tickets. A ticket bumped away and a new one landing inside the same
seven seconds leaves the count unchanged, and that is exactly the arrival nobody
can afford to miss.

Sound is off until somebody asks for it, and the browser-notification permission
is requested at that tap and nowhere else — it is the one moment there is a user
gesture to hang it off, and a page that asks on load is a page everybody denies.

### 16.4 Forwards by default

A bump only ever goes up the ladder, so a double tap on a hot line cannot
silently undo the station next to it. Two things fall out of that:

- A whole-ticket bump skips lines already past the target — a cook who bumped
  one dish early meant it — but **refuses when it moved nothing at all**.
  Reporting success there would let a hand-posted backwards target read as a
  bump, and would tell a cook their tap landed when it did not.
- Going back is `recall()`: a separate method, a separate permission
  (`kitchen.tickets.recall`), a required reason, and a line in the activity log.
  That is what the requirements mean by reopening being for authorised staff. It
  clears the stamps it invalidates, because otherwise the preparation-time
  report quietly stops meaning anything.

### 16.5 The screen itself

Dark regardless of the theme toggle. The rest of the admin follows whatever the
operator picked; this does not, because a white board at this size in a dim
kitchen at eleven at night is genuinely hard to look at — and the people reading
it are not the people who set the theme.

Four columns, one per rung. `served` has no column: a plate that has gone out is
history, and a board that kept showing it is a board nobody can read by nine
o'clock. A ticket sits in the column of its **least-advanced line here**, for
the same reason the order's status is derived that way.

The station choice is sticky in the session, not only in the URL. A screen
bolted to the wall by the tandoor is the tandoor's screen, and it has to still
be the tandoor's screen after the kiosk browser reloads it when the building's
power blinks. `all` is stored as a real answer rather than as an absence,
because the pass wants every station on one screen and that has to survive a
reload too.

**The timers are recomputed, never incremented.** A screen left open overnight,
a laptop that slept, a poll that failed for two minutes — all of them break a
counter and none of them break a subtraction.

Amber and red come from the station's own window: a bar holding a drink order
for six minutes is late, a tandoor six minutes into a raan has barely started.
A card showing two stations takes the tightest of them, which is right — the
table gets the whole thing at once.

### 16.6 The KOT slip has no prices

Sized for an 80mm thermal roll, monospace, quantity in the largest type on the
page. No prices, no tax, no totals, and there never will be: a KOT is a work
order, and one that carried totals would be handed to a guest by accident about
once a month.

Printed for one station it shows that station's lines; printed for the whole
ticket it shows every line with its station's code beside it.

### 16.7 Two roles the requirements asked for

`Kitchen Staff` and `Captain / Waiter` did not exist — a jewellery ERP had no
use for either. Both are in `RolePermissionSeeder` now, and the upgrade
migration hands the new rights to the roles that already do the neighbouring
job, because the seeder only runs on a fresh install and a restaurant that has
been live for a month would otherwise upgrade into a screen its head chef gets
a 403 on.

Two grants are worth stating out loud:

- **A cook does not get `inventory.products.edit`.** The sold-out toggle rides
  on it, so granting it would hand every cook the price of every dish. A kitchen
  that needs the toggle gets it ticked on deliberately.
- **A captain gets `kitchen.tickets.advance`.** It is the runner who knows a
  plate actually reached the table, and Served is the last rung — a kitchen
  marking its own food served would be marking that it put it on the pass.

### 16.8 The preparation-time report

Station by station, with **two waits kept in separate columns**:

| column | what it measures | whose problem it is |
|---|---|---|
| wait to accept | placed until somebody picked it up | staffing, attention |
| time to cook | accepted until it hit the pass | recipe, prep, equipment |

A single "average time" would add them together and hide whichever one is
actually wrong, which is the whole reason the report exists.

It reads `order_items`, not `orders`. With routing on, a ticket is several jobs,
and a per-order average would tell the bar it is slow when the tandoor is.

**Lines the kitchen never finished are counted and not averaged.** An unfinished
line has no duration, and counting it as zero would make a backed-up kitchen
look fast. It gets its own "never reached the pass" note instead.

Late is judged against each station's own window — the same rule the board
colours its cards by, so the report and the screen can never disagree about
whether Friday was bad.

The screen shows `4m 12s`, which is a number a head chef can argue with. The CSV
gives decimal minutes, because a spreadsheet can average a number and cannot
average a string.

**One portability note worth knowing about.** The durations need a function
whose name differs on every engine — `TIMESTAMPDIFF` on MySQL,
`strftime('%s')` on SQLite, `EXTRACT(EPOCH …)` on Postgres — and subtracting two
DATETIMEs is not portable and, on MySQL, is not even meaningful. So
`ReportService::seconds()` picks the dialect. It exists because the application
runs on MySQL and the test suite runs on SQLite: a report written in one dialect
is a report that is never tested.

---

## 17. Settling the table

### 17.1 The bill is an invoice

Everything a dine-in bill needs — GST split by place of supply, a running
number, the ledger, the printed copy — `InvoiceService` already does, and does
identically for the counter. A second document type would be a second place for
the tax to be wrong.

So `TableBillService` does only the part that is actually about tables: which of
a sitting's lines go on which bill, and what happens to the table afterwards.

### 17.2 One sitting, several bills

§6 asks for a split "by item, quantity or amount". Those are not three features:

| split | what it actually is |
|---|---|
| by amount | one bill, several payments — `InvoiceService` already |
| by item | two bills, each with some of the lines |
| by quantity | the same, with one line divided between them |

`order_items.settled_quantity` carries the last two. A **quantity and not a
flag**, because "three of the five beers are mine" is a real split and a boolean
cannot say it. A line is done when it reaches its own quantity, whether that took
one bill or three.

The link therefore lives on `invoices.table_session_id`, not on the session: a
split is several invoices off one table, which is the one-to-many the other way
round from the column that used to be there. That old column was removed rather
than left — nothing read it, and a column that looks authoritative while being
unable to represent a split bill is a trap for whoever reaches for it next.

The sitting **closes when nothing is left outstanding** and the table goes to
`cleaning` — never straight to `available`, because a party has just left it. A
partial settle only marks the sitting `billed`, which stops it taking new orders:
somebody who has paid their half should not be able to add a dessert to a bill
that has already been printed.

### 17.3 What the bill charges

The price the guest was shown, add-ons included, passed as an explicit
`unit_price`. Re-pricing at the till would let a menu change between the order
and the bill charge somebody a figure they never saw.

The line is also **named** for the bill: `Margherita (7 inch) — Cheese burst`.
The size and the add-ons are rows in other tables, and the product's own name
would print a bill the guest cannot reconcile with what they ate.

### 17.4 A dish is made, not taken off a shelf

This is the one that would otherwise have made the whole feature useless.

A restaurant has no stock of Butter Naan. It has flour, butter and a cook. But
`InvoiceService` issues stock for every line it bills and refuses when the shelf
is empty — which is right for a shop and catastrophic at a table, because by the
time the bill is raised the food has already been eaten. Left alone, every
restaurant would find its tables unbillable on its second day of trading.

So `products.is_made_to_order`: on for anything the kitchen makes. Selling it
allocates no lot, checks no availability and moves no stock.

**A flag and not a rule**, because a restaurant sells both kinds of thing. The
biryani is made to order; the bottle of water in the fridge is stock, and selling
one really should take one off the count. The backfill reflects that — it skips
anything already tracked lot by lot, since a product with an expiry date is being
held on a shelf whatever else is true of it.

§10's recipes will hang off exactly this flag: a made-to-order dish will deduct
its *ingredients* when it is cooked, which is the deduction that was always the
right one. Until then it deducts nothing, which is honest — a count of Butter
Naan was never a number anybody could act on.

The same flag stops `OrderService` reserving a plate of food the way a warehouse
reserves a box, so an online biryani order is no longer refused on a night the
kitchen can perfectly well cook it.

### 17.5 Moving and merging

**A moved ticket keeps its number.** It embeds the table it was sent from and it
has already been called out across a kitchen; renumbering it would mean the slip
on the pass and the screen no longer agree, which is how a plate goes to the
wrong room.

**A table that has been billed cannot be merged away.** That invoice names a
sitting, and moving its lines afterwards would leave a filed document describing
food that is now on somebody else's table.

Anything picked but not yet sent moves too, or guests would watch their choices
vanish when the tables were pushed together.

### 17.6 A captain with a pad

The guest's phone is not the only way food reaches the kitchen, and in most
restaurants it is not the usual one. So the bill screen has a **Take order**
button, and behind it is the same menu card the phone shows.

Deliberately **the same cart and the same service**. A captain and a guest must
not be able to put different things on one table, and two paths into one cart
would be two sets of rules about sizes, add-ons and what is sold out. In
practice that means a captain gets refused for exactly the reasons a guest does:

    Choose a size for Chicken Biryani.
    How spicy?: Choose 1.

The pad is `table_cart_items` — picked, not sent. Nothing is cooked and nothing
is owed until **Send**, which goes through `TableOrderService::place()` and so
produces exactly the kind of ticket a guest's round does: numbered, routed to
its stations, and on the kitchen display in seconds.

Anything left on the pad shows on the bill as a notice rather than as a line,
so a cashier about to settle can see that a round is still being written and
ask before printing.

`pos.tables.order` is separate from `pos.tables.settle` because they are
different jobs: the floor takes the order, the counter takes the money.

**One consequence worth knowing about.** Every channel writes to `orders`, so
the screen that used to be "Online Orders" is now simply **Orders** — the
history for all four — with a channel filter that narrows it back down. Left
as it was, a restaurant's storefront screen would have quietly filled up with
dine-in tickets and nobody could have told which was which.

### 17.7 Hold and resume

§6 lists it, and for dine-in **the table is the hold**. An order sits on its
sitting indefinitely and is resumed by opening that table — there is nothing to
park and nothing to name. A parked basket is a counter feature, for the customer
who walks off to fetch their wallet, and it belongs with the till rather than
here.

### 17.8 Two things the screen says rather than refuses

**Food still in the kitchen.** A guest asking for the bill while the last dish is
on the pass is a normal Tuesday; a table where nothing has been accepted usually
means somebody pressed the wrong one. The screen says which it is and lets the
cashier decide — a rule here would get in the way of somebody leaving.

**Clearing a table unpaid** is the one action that ends a sitting with money owed
and no document raised, so it has a right of its own (`pos.tables.write_off`,
which matches the elevated pattern no role picks up by accident), a required
reason, and a line in the activity log. It is refused outright once anything has
been billed: a sitting with an invoice against it is an accounting record, not a
table to be tidied away.

---

## 18. Recipes: what a dish is made of

### 18.1 An ingredient is a product

Flour, butter and chicken are bought from suppliers, received against purchase
orders, counted on a shelf, costed and reported on. Every one of those is
something `products` already does.

A second master for "ingredients" would have meant a second place to receive
stock into, a second costing rule and a second set of low-stock alerts — all of
them subtly different from the ones that already work. So an ingredient is a
product with `is_ingredient` set, which keeps it off the menu, the storefront
and the counter's search while leaving every inventory screen exactly as it is.

That is now three flags on a product, and they are not the same question:

| flag | asks |
|---|---|
| `is_made_to_order` | does selling this move stock? |
| `is_ingredient` | is this sold at all? |
| `track_batches` | does it have lots and an expiry? |

A biryani is the first, flour is the second, bottled water is neither.

### 18.2 Why the size matters

A Full biryani uses more rice than a Half, and a restaurant that costed both the
same would price one of them wrong.

A `recipe_items` row naming a variant **replaces** the generic rows for that
size rather than adding to them. Two half-recipes that had to be read together
would mean nobody could look at one screen and know what a Full actually
contains — and the first person to add rice to the Full without removing it
from the shared list would double it.

    generic:  0.18 rice, 0.22 chicken   ->  Half  ₹62.00
    Full:     0.30 rice, 0.38 chicken   ->  Full  ₹106.00

The Half has no recipe of its own, so it falls back to the generic — and it
falls back, rather than adding.

### 18.3 The quantity is in the ingredient's own unit

Four decimals, because a pinch of saffron in kilograms is 0.0002 and a recipe
that rounded it to zero would cost the dish wrong forever.

**There is deliberately no unit conversion.** `units` has no factor, and
inventing one here would mean two places that disagree about how many grams are
in a kilogram. A kitchen that thinks in grams stocks its flour in grams — GM and
ML are both in the unit list — and the form shows the ingredient's unit beside
every box, so nobody types 150 meaning grams into a field counted in kilos.

### 18.4 The kitchen consumes, not the till

A dish moves no stock when it is *sold* (section 17.4), so the ingredients come
off in the kitchen, when a line reaches whichever rung the outlet configured:

    off | accepted | ready | served          shops.recipe_deduction

Default `ready`: a ticket that never gets there was never cooked, and counting
its ingredients would make the stock report describe food that does not exist.

Hanging it off the *bill* would have been wrong for a more interesting reason: a
dish that was cooked and then comped, or sent back, or eaten by the staff, has
used its ingredients either way. A kitchen that gave away four plates on a
Friday would have shown no consumption for them.

The rung is compared **by position on the ladder, not by equality**. A line
bumped straight from Placed to Ready has passed the accept moment without ever
sitting at it, and an equality check would lose its ingredients forever.

### 18.5 Once, and never given back

`order_items.recipe_consumed_at` is the guard, and it is on the *line* because
with station routing a ticket's lines reach `ready` at different times in
different rooms.

It is never cleared. A ticket recalled because a plate came back cold has still
used its ingredients — recalling it does not un-cook the food — and giving the
stock back would make a kitchen that sends nothing back look like it is losing
flour.

A dish with **no** recipe is stamped too. Leaving it null would make every
subsequent bump re-ask the same question for the rest of the evening.

### 18.6 Consumption is never refused

`StockService` exempts `CONSUMPTION` from the availability guard, whatever the
shop's `allow_negative_stock` says.

That setting is a commercial decision — may the counter sell something it does
not have? This is not that question. The flour has already gone into the naan,
and refusing to *record* it because the count disagrees leaves the shelf reading
as full when it is empty, which is strictly worse than a negative balance.

A negative here is a true statement about a kitchen that has not been counting,
and it is exactly what the low-stock alert exists to shout about.

### 18.7 The screen is a list, not a tab

"Which of my dishes am I not making money on" is a comparative question, and
answering it forty clicks at a time is not answering it. So recipes are one
screen listing every dish with its cost, its price and its margin — coloured
below 50%, because a kitchen runs 25–35% food cost.

The cost is read from the ingredient's **purchase price**, not from a stock
average. This screen answers a planning question, and an average that swung with
every delivery would show the same recipe costing something different each week.
The *sold* cost is captured on the invoice line, which is a different number and
deliberately so.

The editor needs no JavaScript: existing rows plus six blank ones, and a blank
row is skipped on the way in. Removing an ingredient means clearing its select.
A dish with more than six new ingredients is saved and reopened — rarer than a
screen that needs a script to be usable at all.

---

## 19. Wastage, and getting a menu in

### 19.1 Wastage is a log, not a document

`stock_adjustments` already has damage, expiry and theft among its reasons, and
it is the right machinery for a stock-take: a document that goes draft →
pending → approved and sets a slot to a **counted** quantity.

Wastage is none of those things. It is "the chef dropped a tray of paneer at
eight". It is a delta rather than a count, it has to hit the shelf immediately,
and the person recording it is holding the empty tray rather than sitting at a
desk waiting for an approval.

Routing it through a three-state document is how a kitchen stops recording it at
all — and unrecorded waste is the single easiest way for a restaurant's food cost
to be wrong by ten percent with nobody knowing why. Once it is written down,
"waste", "theft" and "over-portioning" become three different conversations
instead of one unexplained number.

So: one row, one movement, immediately. `create` is a wide right on purpose;
`delete` is the narrow one, because being able to un-record a loss is being able
to hide one.

### 19.2 Two numbers, because there are two questions

    Lost              ₹680      the kitchen's failure
    Written off       ₹940      everything, including staff meals and tastings

A staff meal is stock that left unsold, not a failure. It is still deducted —
the food is gone either way — but a headline that folded it into "waste" would
have a manager chasing something that is working as intended.

The reasons are short and specific for the same reason. A list that offers
"Other" first is a list where everything is Other, and each of these is a
different conversation with a different person: `spoiled` means the store is
over-ordering or the fridge is failing, `burnt` means the line is rushing or a
recipe needs its timing revised, `dropped` is an accident and the number to watch
is the trend.

**The value is frozen** at what the stock actually cost — the slot's average
cost, captured at the moment it is written off. Re-deriving it later would let a
delivery at a different price restate what last month's waste was worth, and this
number's whole job is to be added up over a month and shown to somebody.

**A made-to-order dish cannot be written off.** There is no count of Butter Naan
to take away from. Refused rather than silently ignored: somebody writing off two
portions means the flour and butter that went into them, and recording nothing
would leave them believing their store had been corrected.

**Reversing writes a second movement** rather than deleting the first. A ledger
that can lose a row is a ledger nobody can reconcile — "why is there 3 kg less
than yesterday" has to stay answerable even when yesterday was a mistake.

### 19.3 Bulk menu import

A restaurant signing up has three or four hundred dishes already written down
somewhere. Typing them into a form four hundred times is not an onboarding
process, it is a reason to pick a different product.

**One bad row imports nothing.** Every row is validated before any row is
written. A half-imported menu is worse than none: the operator cannot tell which
rows landed, re-running would double what did, and the only way back is to delete
four hundred dishes by hand. The errors come back with the **spreadsheet's own
row numbers**, because that is what the person is looking at while they read
them:

    Row 3: a dish needs a name.
    Row 4: there is no unit called "SPOON".
    Row 5: "meaty" is not a food type. Use one of: veg, egg, non_veg, vegan, jain.

**The export and the import share one row builder.** A file that downloads with
different columns from the ones the import reads is the commonest way a bulk
import wastes an afternoon. So it round-trips: download the menu, change forty
prices in a spreadsheet, upload it — forty dishes updated, not four hundred
duplicated. Matching is on the SKU, and on the name when there is no SKU.

**A blank cell leaves the field alone**, and does not clear it. The column is
often blank on a file somebody edited down to forty prices, and reading that as
"remove the unit from forty dishes" would be catastrophic.

**Categories that do not exist are created**, and named back in the result.
Refusing them would mean "set up your twelve categories first", which is the
chore that makes a bulk import pointless — and reporting them means a typo that
invented "Dessrts" is visible immediately rather than a month later.

**Stock is never imported.** It moves through receipts and adjustments, which is
the only way it stays reconcilable. A column somebody edits that is silently
ignored is worse than no column, so it is not in the file at all — which is why
the old jewellery-era export columns (MRP, on-hand, published) went when the
menu ones arrived.

Preview posts the same form with a flag, so the two can never disagree about
what the file says.

---

## 20. The support desk

§2 lists support tickets among the things Super Admin manages, beside
restaurants, plans and subscriptions. Until recently the only trace of it was a
`data-soon` link in the footer.

### 20.1 It hangs off the tenant, not the shop

Every other operational model here carries `BelongsToShop`. `SupportTicket`
carries neither that nor `BelongsToTenant`, and both absences are deliberate.

A ticket belongs to the **company**. Its `shop_id` is a detail *about* it — "the
rooftop printer", "the QR sticker on table 12" — and is nullable, because plenty
of tickets are about the account rather than a branch. Applying `BelongsToShop`
would narrow the list to whichever branch the reader is currently switched to,
so an owner who raised a ticket about the rooftop would lose it the moment they
switched to the ground floor.

`BelongsToTenant` is not used either, for the opposite reason: a global tenant
scope would make the platform's own queue invisible to the people who work it,
and the escape hatch would then sit on the hot path rather than the cold one.

**So scoping is explicit, and it lives in exactly one place:**
`SupportTicket::scopeVisibleTo()`. It widens to everything for a holder of
`support.tickets.manage` and narrows to `CurrentTenant::accessibleIds()` for
everybody else.

This is the one model in the codebase whose **failure mode is open, not empty** —
forget the filter and you leak another company's thread, rather than showing a
blank page. That is why the controller re-resolves every implicitly bound
`{ticket}` through `visibleTo()` before touching it, and why
`tests/Feature/SupportTicketTest.php` spends most of its length on the boundary
rather than on the CRUD.

A ticket the reader may not see returns **404, not 403**. Telling somebody that
TKT-26-00042 exists but is not theirs is itself a leak about who else is on the
platform.

### 20.2 One controller, two audiences

The restaurant raising a ticket and the platform staff answering it use the same
routes, the same list and the same thread. Only the *scope* of the list and the
set of buttons differ, and the buttons follow a single `$canManage` flag.

Two controllers would have meant two copies of a conversation view — exactly the
kind of pair that drifts until an internal note renders on the customer's
screen.

### 20.3 Internal notes

A note is a reply with `internal = true`, in the same table and in sequence with
the messages it is about. The desk needs it interleaved; a separate table would
leave them reading two lists and merging by hand.

The filter is `SupportTicket::visibleReplies()`, a relation rather than a
`where()` at each call site, so it cannot be forgotten. Both `internal` and
`from_staff` are decided **in the controller from the actor's rights**, never
from the form — a restaurant posting `internal=1` would otherwise write a note
the desk reads as its own, and `from_staff=1` would forge an answer into their
own thread. Both are one curl away and both are tested.

### 20.4 Status is stored, and it is the one thing here that is

Elsewhere a status that can be derived is derived — the subscription reads its
state off `ends_at`. A ticket is the opposite case: "waiting on the customer"
and "waiting on us" cannot be computed from reply timestamps without guessing at
intent, because an agent who replies with an answer and one who replies with a
question leave identical rows behind.

So the service moves it, and nothing else writes `status`, `last_reply_at` or
`first_responded_at`:

| who replies | ticket moves to |
|---|---|
| the restaurant | `awaiting_support` |
| the desk | `awaiting_customer` |
| the desk, internally | nowhere at all |

`first_responded_at` is stamped **once and never moved** — it cannot be
recovered later, and it is the only number a support desk is judged on.

**Resolved and closed are different states.** A reply to a resolved ticket
reopens it, because "that did not fix it" is the most useful message a desk gets
and turning it into a second ticket loses all the context. A closed ticket
refuses replies; that is the entire difference between the two.

### 20.5 `manage` is platform-only, and the seeder enforces it

Most role grants in `RolePermissionSeeder` are written as exclusions —
"everything except `settings.*`". That shape is right for a role and wrong for a
right that must never be handed out, because the default is *yes*: a new
cross-tenant permission lands in Tenant Owner, Shop Admin and Manager the moment
somebody runs the seeder, and nothing would say so.

So the rule is inverted once, in `isPlatformOnly()`: any permission ending in
`.manage` is refused to every role outside `PLATFORM_ROLES`, whatever that
role's own grant says. Adding another cross-tenant right is a line there rather
than an edit to nine closures, and forgetting to add it **fails closed**.
`test_no_customer_role_holds_the_manage_right` is what fails if that ever slips.

---

## 21. Backups and system health

§17 asks for automated backups with restore testing, queue failed-job
monitoring, and daily error-log and health monitoring. All three are the same
kind of failure: one that nobody is standing in front of. A job that threw at
two in the morning, a backup that stopped running in March, a scheduler nobody
restarted after a reboot — no till and no kitchen screen will ever mention any
of it.

### 21.1 The backup is `mysqldump`, not a PHP dumper

What is being protected is a restaurant's takings, and a backup that cannot be
restored is worse than none — it is the same risk plus false confidence.
`mysqldump` produces a file MySQL itself will read back; no amount of
hand-written `INSERT` generation gives that.

The cost is a hard dependency on the binary. That is stated rather than worked
around: `backup:run` checks, and fails with a message naming the problem, so a
server missing it says so on the first scheduled run instead of writing empty
files for six months. On a non-MySQL connection it **warns and exits 0** — the
test suite runs on SQLite and must not fail on a command that did the right
thing.

**The password never reaches the command line.** Arguments are visible to every
user on the box through `ps`, so credentials go into a temporary 0600 defaults
file passed with `--defaults-extra-file`, deleted in a `finally` — including
when the dump fails, which is exactly when it would otherwise be left on disk.

`--single-transaction` with `--skip-lock-tables`, because a restaurant's
database is written to continuously; without it the dump is a set of tables from
slightly different moments, and an order can exist with no invoice.

**Retention is counted in files, not days.** A server whose scheduler was down
for a week should not wake up and delete every backup it has because they are
all "too old".

### 21.2 Half of restore testing, said plainly

`--verify` checks the dump is a plausible, complete SQL file: non-trivial in
size, and ending with the `Dump completed` marker `mysqldump` writes last. That
catches the realistic failure — the disk filling mid-write — and a file that is
an "error connecting" message where a database should be.

It does **not** load the dump into a scratch database. That needs a second
server and is a deployment decision rather than a code one. Said here so it is
not discovered later.

### 21.3 Paths are config, and there is a reason

`config/backup.php` holds the dump directory. It is config rather than a
`storage_path()` call for two reasons, and the second was learned the hard way:
a test asserting the "no backups yet" state cleared the backup directory and
deleted a real dump off the developer's machine. The suite now points both that
path and the log path at a temporary directory it owns.

The same applies to the error log: `SystemHealthService::recentErrors()` reads
the path from the logging config rather than hard-coding `laravel.log`.

### 21.4 The health screen degrades rather than throws

Every check reads something outside the database — the disk, the log file, the
backup directory, a `jobs` table that a Redis deployment does not have. Any of
them can be missing on a freshly deployed server, which is precisely when
somebody first opens this page. **A health screen that 500s is the one thing
worse than no health screen**, so each check renders as "cannot tell" beside the
ones that worked, and `test_it_renders_on_a_fresh_server` holds that line.

The log reader seeks to the end of the file and reads a fixed window backwards
rather than loading it — the difference between a page that opens and one that
exhausts memory on the server it was opened to diagnose. Only `ERROR` and above
are kept: a log full of `INFO` is how the one line that matters gets missed.

Checks return a **tone**, not pass/fail, because the useful question is not "is
it broken" but "should somebody look today".

### 21.5 Reading is separate from doing

`settings.health.view` opens the screen. Retrying a failed job re-runs whatever
it was — a payment webhook, a campaign send — possibly with a real-world effect
a second time, so it is its own right; discarding one destroys the only record
of what went wrong, so that is another.

The whole module sits under `settings.*`, which means every customer-side grant
already excludes it without a single extra rule. That default matters: the
screen prints the tail of the error log and the name of every failed job, which
between them leak table names, file paths and the shape of the deployment.

The on-demand backup runs **synchronously**, not on the queue. The queue is one
of the things this screen exists to tell you is broken, and a backup dispatched
onto a queue nobody is working would report "started" and never happen.

---

## 22. Signing up, and paying for it

Until this section existed, an account was four rows an administrator typed:
a tenant, its first shop, an owner's login and a subscription. Every one of
those screens was in the back office, which meant the person actually buying
the software could read the pricing at eleven at night and then wait for
somebody to open it in the morning. Section 22 is the same four rows, written
by the customer.

### 22.1 One service, not a second way of making an account

`SignupService::register()` writes all four inside one transaction and uses
the models, slug rules and `SubscriptionService` the admin screens use. A
half-made account is worse than none — a tenant with no outlet cannot be
subscribed, an outlet with no owner is invisible to the person who just paid
for it, and both look correct on a list screen — so either all four land or
the visitor sees the form again.

The owner gets `is_admin` and the **Tenant Owner** role, which is what makes
the account usable: the back office is the product, and the role grants
everything inside their own company and nothing outside it. The shop's
`modules` column is left **null** on purpose — null means every line of
business, which `Modules::forShop()` then caps by what the plan grants, so the
plan stays the only ceiling.

Codes are derived from the name, because a visitor will never be asked to
invent one, and soft-deleted rows count as taken — the same rule
`HasUniqueSlug` follows, and for the same reason.

### 22.2 A plan with no trial must not open a free account

`SubscriptionService::subscribe()` leaves `ends_at` null when there is no trial
and no earlier term to carry over, and a null `ends_at` means **perpetual** —
the right answer for an in-house account and exactly the wrong one for somebody
who signed themselves up ninety seconds ago. So for that case `SignupService`
closes the term immediately and sets `grace_days` to zero: the outlet exists,
the owner can sign in, and the only screen they can reach is the one that takes
their money. Signing up for the most expensive plan must not be the cheapest
way to use the software.

### 22.3 Two steps, and a URL per plan

`/signup/starter`, not `/signup?plan=starter`: one address per plan, which is
what can be read out over a phone, what a search engine treats as one page
rather than three taggings of one, and what survives being pasted into a chat
client that drops everything after the `?`. The old query form is answered with
a 301 rather than broken, because those links are already out there.

Choosing a plan and filling in details are two pages, not one page that hides
half of itself. The business name, the city and a password underneath a price
list is a long form on a phone, and the one decision the visitor had already
made scrolled out of sight while they filled it in. Two real addresses also
mean Back goes back a step and a half-finished signup can be returned to —
none of which a JavaScript wizard gives you for free.

Step one's tabs are links; step two carries the plan in a hidden field and
settles monthly-or-yearly beside the button that commits to it.

### 22.4 The signup form is public, so it is defended like the demo form

A honeypot first, because it costs an honest visitor nothing; a rate limit on
the POST second, tighter than the demo form's because this one writes four rows
and a login rather than a row in an inbox; and no CAPTCHA, for the reason
`DemoRequestController` gives — this form is how the business gets paid.

It needs no JavaScript. The plan and the billing period are radios rather than
a live price widget, so the page works for the owner filling it in on a phone
on a bad connection.

### 22.5 Billing is the customer's side; `SubscriptionController` is ours

`settings.subscriptions.edit` still does not belong to a customer: a business
that could type a new expiry date into a form is not on a subscription.
`BillingController` does not write dates either. It opens a `PaymentIntent`
against the `Subscription`, the provider takes the money, and
`PaymentIntentService::capture()` credits it through
`SubscriptionService::recordPayment()` — the same method a bank transfer goes
through. The money is what moves the term.

The credit happens in `settleWhatWasPaidFor()`, beside the table-bill case, so
the browser callback and the webhook both reach it and a phone that died on the
provider's screen still opens the outlet. Which term was bought is read back
off the amount rather than carried through the checkout: a period smuggled in a
form field would be a month's money buying a year.

### 22.6 The one gate billing must not have

Its routes are outside `shop.subscribed`. An outlet whose term has run out is
precisely the outlet that needs the page, and a checkout you have to be paid up
to reach is a support ticket rather than a payment.

That is also why `EnsureShopIsSubscribed` now sends a blocked dashboard request
to billing rather than to `subscription.mine` — that screen is inside the gate,
so the old redirect bounced between two pages that both redirect. Somebody who
may not open billing at all (a cashier at a lapsed branch) is told plainly with
a 403 instead, because there is no way out to send them to.

### 22.7 No keys is a working state

When no gateway is configured the page draws no button. It says what is owed,
who to send it to, and which account to quote — the same rule the guest's table
page follows, and the reason an install with no Razorpay keys is a system that
works rather than a system with a broken button on it.


### 22.8 Forgetting the password you just chose

Self-serve signup broke an assumption the back office was built on: that every
password belonged to somebody an administrator could reach and send a fresh
welcome link to. The owner who signed up at midnight has no administrator.

`PasswordResetController` issues a token and mails a link to the screen the
welcome email already uses — one page that sets a password from a token, two
ways of getting one. It answers identically whether or not the address has an
account, because a form that said "no account with that email" is a way to
enumerate customers; what actually happened goes to the activity log.

It issues `welcome` tokens rather than `users` ones, and that is not a
preference about link lifetimes. Both brokers are configured on the same table,
so the broker performing the reset is the one whose expiry applies: a one-hour
token would still be accepted three days later by the screen that consumes it.
Shipping an email that states a window the code does not enforce is worse than
the longer window, so the enforceable one is what is used and what is said.

Nothing is written to `login_histories`. That table has three states — success,
failed, blocked — and a reset request is none of them; filing it as `failed`
would put somebody who forgot a password into the failed-sign-in counts the
blocked-IP screen reads.

### 22.9 What the public pages do with JavaScript

Nothing they need. The landing page, the signup steps and the demo form all
post, redirect and flash a message on their own. `public/assets/js/public-forms.js`
is loaded deferred and upgrades that: the post goes through `fetch`, the answer
is a toast, and a field error is drawn beside its own field rather than in a
list at the top of a reloaded page. Blocked, failed or switched off, the
browser submits the form itself and the visitor notices nothing.

It is deliberately not `assets/js/app.js`: that is the admin panel's bundle, it
is twenty times the size, and it reaches for markup these pages do not have.

The controllers answer both shapes from one method — `expectsJson()` decides —
so there is one validation path and one set of messages, not a web version and
an API version that drift.

---

## 23. Who sees whose data

Self-serve signup (§22) turned a single-business install into a platform with
strangers on it, and that changed what a missing filter costs. Three separate
things were leaking, and they are worth telling apart because they failed for
three different reasons.

### 23.1 The screens that define the scopes

`ShopController` and `UserController` are deliberately outside the scopes they
describe — Shops is the screen that says what a shop is, Users is where staff
are created. Being outside them, both had to answer "whose?" by hand, and
neither did: a Tenant Owner opening either saw every outlet and every account on
the platform, with addresses, GSTINs and email addresses.

Both now read through a `readable()` query keyed on
`CurrentTenant::accessibleIds()`, which is one row for a customer and every row
for a Super Admin. Their stat tiles count through the same query — a tile
reading "42 staff" over a list of three is both a leak and a bug report.

The list was only half of it. Route-model binding hands
`/admin/shops/5/edit` any row whose id is typed into the address bar, so every
screen taking a `{shop}` or a `{user}` calls an `authorise()` guard first and
answers 403.

### 23.2 The masters that belonged to nobody

Products, categories, brands, units and tax rates had no owner column at all.
On one business that is invisible; with two it means the restaurant that signed
up this morning opens Products and finds somebody else's menu — editable, and
already on its own table QR and storefront.

They now carry `tenant_id` and use `BelongsToTenant`. The company and not the
branch, because a group shares one menu: what varies per outlet (price, stock,
whether a dish is listed) already lives on `product_shop`, `product_stocks` and
the price lists.

The unique indexes had to move with the column. `products.slug`, `products.sku`,
`categories.slug`, `brands.slug` and `units.code` were unique across the table,
so the second restaurant to sell a Paneer Tikka was refused because of a row it
could not see. Each is now unique within a company.

### 23.3 Guests have no tenant, so the public pages name one

`TenantScope` steps aside when there is no authenticated user — it has to, or
the queue and the console would see nothing. Every public page is exactly that
case, so the storefront and the table QR menu would have been unfiltered.

`Product::scopeAvailableAt($shopId)` therefore reads the branch's company off
the shop and filters on it, which fixes every storefront query at once because
they all already called it. `MenuService::items()` and the storefront's category
and brand filters name the tenant the same way. The tenant id is read from the
shop being served, never taken from the request: a tenant id a caller can pass
is a tenant id a URL can carry.

`TableOrderController` had a comment claiming its product lookup was scoped to
the branch. It was not, and could not be while the catalogue had no owner — a
guest could add another restaurant's dish by id. It calls `availableAt()` now,
and the comment is true.

### 23.4 A new business starts with a vocabulary, not an empty room

Scoping the masters had an immediate consequence: a brand-new company owns no
units and no tax slabs, and a product cannot be saved without a unit. The first
thing the software would have said to a new customer is "invent kilogram".

`App\Services\CatalogStarter` holds the units and the GST slabs and gives a copy
to each company, plus a Main Store warehouse to each outlet. `CatalogSeeder`
calls it for the install; `SignupService` calls it for the business signing up
tonight. One list, two callers.

Copies rather than shared rows, because these get edited — a business corrects a
slab when the law changes. Shared rows would make one restaurant's correction
everybody's, which is the failure this whole section exists to stop.

### 23.5 What was never leaking

Everything carrying `shop_id` — invoices, orders, customers, suppliers,
expenses, stock, coupons, tables, kitchen stations — was scoped from the start
by `BelongsToShop` and was correct throughout. The platform's own content
(plans, blog, FAQs, testimonials, landing sections) is deliberately global: it
is the product's marketing page, not a restaurant's.

### 23.6 Global to read is not global to edit

Saying the platform's content is global settles who it belongs to and says
nothing about who may change it. The sliders, blog posts, reels, FAQs, services
and Instagram tiles are the marketing page a visitor lands on before any
restaurant exists — one page, ours. But `content.*` was handed out with the rest
of the module rights, so every restaurant that signed up could open Content and
rewrite the home page that sells the product to the next one.

`RolePermissionSeeder::platformOnly()` now covers `content.` alongside
`settings.`, and neither is granted to Tenant Owner, Shop Admin, Manager,
Employee or Auditor. Those screens answer 403 to a customer and open for us.

This also undid an earlier correction. Collections and events had been given a
`tenant_id` on the theory that they were a restaurant's own; reading the views
showed they are landing-page sections like the rest, so the migration was rolled
back and the trait removed rather than left as a column nobody sets. A scope on
platform data is not a smaller mistake than no scope on tenant data — it is the
same mistake pointing the other way.

### 23.7 The audit trail had no owner

`settings.activity_logs.*` belongs to an owner on purpose: someone has to be able
to ask who voided last night's bill, and that is the screen that answers. The
table, though, had no owner column, so it answered for every business on the
install — staff names, email addresses, IP addresses, and a line describing each
action another restaurant took.

The read was the smaller half. The same right prunes, and one customer trimming
their own log to ninety days took everybody's with it.

`activity_logs` now carries a nullable `tenant_id`, stamped in
`ActivityLog::record()` from `CurrentTenant::id()` and falling back to the
actor's own company. The index, the events filter, the CSV export and the prune
all go through one `readable()` method, so a right that is granted once cannot be
scoped in three places and forgotten in the fourth.

Existing rows were handed to the actor's company, which is the only honest answer
the table can give: it records who, and a user belongs to one business. Rows
written by nobody — the scheduler, the installer, a console command — and rows
written by a Super Admin stay null. They are platform events, and null is exactly
who can still see them.

The column is nullable and the foreign key nulls on delete, deliberately. An
audit trail that vanished with the account it audits would be the one thing an
auditor asks for after a dispute.

### 23.8 A shopper is not a member of staff

`TenantScope` stepped aside for "no actor" - console, queue workers, seeders -
by asking `Auth::hasUser()`. A storefront customer signing in made that answer
yes, because `actingAs()` and the storefront login both make `customer` the
current guard.

So a shopper became an actor with no company, and every catalogue read filtered
to nothing. The shop's own products vanished from its own storefront the moment
a customer logged in, their cart emptied on screen, and each product's unit and
tax rate resolved to null. A guest browsing the same page saw all of it. Signing
in made the shop disappear.

The scope now asks whether the actor is one of ours - `Auth::user() instanceof
User` - rather than whether anybody is there at all. Whose company a row belongs
to is a staff question. A shopper is scoped by the outlet they are standing in,
which every storefront query already names for itself with `availableAt()`,
`forTenant()` or `forShop()`, exactly as it must for a guest.

`ShopScope` deliberately still asks the old question. It fails closed - a
customer sees nothing rather than everything - and every storefront query names
`shop_id` itself, so nothing depends on it opening.


## 24. What the outside world reads

The marketing page was given a description, a canonical, share cards and
JSON-LD when it was written, because it is the page being sold from. The
storefront - the page a restaurant's own customers are actually sent - was
given a `<title>` and nothing else, and nobody noticed because nobody on the
team ever arrives at a shop from a search result.

### 24.1 One head, not a block of tags per view

`App\Support\StorefrontSeo::resolve()` composes the whole of it: title,
description, canonical, `robots`, the Open Graph and Twitter pairs, and any
structured data. The layout renders what it returns.

One place because the rules do not vary. The outlet is the site, its own URL is
the canonical one, and the values are the only thing that changes from page to
page. A view says what it is with `@section('title')`; a controller that knows
more than the view - a product's own meta description, a category's canonical -
passes a `$seo` array and that wins.

The title had been printing the shop name twice on the two pages that set no
section title, because the fallback was the shop name and the layout appended
it again.

### 24.2 The SEO tab that did nothing

Products have carried `meta_title`, `meta_description` and `meta_keywords`
since the beginning. The admin screen collects them, validates them, saves
them, and shows them back on the edit form. No page a customer or a crawler
ever reached was different for any of it.

That is worse than not having the fields. A shop owner filling in an SEO tab
believes they have done something.

They are read now, with the copy already on the page as the fallback, so a
product nobody has written SEO for still describes itself rather than
describing the shop.

### 24.3 Indexable is a list, not a judgement

`StorefrontSeo::INDEXABLE` names the four pages worth putting in a search
result - the shop's front door, its catalogue, a category and a product. Every
other page answers `noindex, nofollow`.

A list of what may be indexed rather than a list of what may not, so a page
added next year is private until somebody decides otherwise. The other way
round is how a stranger's basket, or an order confirmation with a phone number
on it, ends up on a results page.

### 24.4 The sitemap stopped listing the same URL two hundred times

Blog posts are drawn as sections of the landing page and have no address of
their own, so the only URL the sitemap could emit for one was the landing page
with a `#blog-<slug>` on the end. A crawler drops the fragment before it asks
for anything, so two hundred posts were two hundred copies of one entry, each
overwriting the last one's `lastmod`.

They are not listed. If a post is ever given a page of its own, the loop goes
back.

The two query-string rules in `robots.txt` had the same shape of bug: `/*?utm_`
only matches a link whose campaign tag happens to be the first parameter, and
the ones that arrive in the wild rarely are.

### 24.5 The guest menu is not indexed, on purpose

`/t` sets `noindex, nofollow` and always has. A table session belongs to one
phone and means nothing to anybody else, so there is no version of it worth
putting in a search result.

## 25. Two settings the database is not allowed to decide

Most of Settings > General is the operator's to change, which is the point of
having the screen. Two things on it are not, and both were.

### 25.1 Which mailer runs

`MailConfigurator` applied the saved mail settings over whatever was in `.env`,
including the mailer itself. `.env` on this install says `MAIL_MAILER=log`, the
settings table said `smtp`, and the settings table won - so a machine everybody
believed was writing mail to a log file was authenticating to Gmail and
delivering to real inboxes, including to any address a stranger typed into the
public forgot-password form.

Which transport runs is a deployment decision. It is the setting that decides
whether a message leaves the machine at all, and a row in a table the
application itself can write must not be able to overrule it. `MAIL_MAILER`
now wins when it is set, and the screen decides only when the environment has
stayed silent. The host and the credentials still come from the screen: those
are an operator's, and once the mailer is `log` they are never read.

The environment is read twice on purpose. Normally `env()` answers, but a host
that has run `config:cache` never loads the `.env` file at all, so `env()`
returns null there for a key that is certainly set. In that case the cached
`mail.default` is literally what `.env` said when the cache was built, and
nothing has overwritten it yet at that point in the boot.

`CompanySettingSeeder` also seeds `log` rather than `smtp`, so a fresh install
does not arrive pointed at a live mail host and send its first real message
while somebody is still setting it up.

### 25.2 Blocking an address for everybody

The security screen offers a block scoped to one account or to the platform.
The platform-wide one writes a row with no `user_id`, and `ip.allowed` then
turns away every request from that address - including the other restaurants on
the install, who never agreed to it.

An owner blocks an address from their own staff account. Choosing the platform
is ours, and so is lifting one: a lift reached through `/admin/users/{user}`
was not scoped by that user either, so a global block could be taken off by
anybody who could guess its id.
