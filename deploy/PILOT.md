# Running the pilot

This is how one real restaurant goes live on the Suite - Accounts + CRM + ERP,
talking to each other - and how you know each part works before the next. It
builds on [README.md](README.md), which gets the three apps deployed and their
events flowing (Gate G0). Everything here is run on the server unless it says
otherwise; `<name>` is `khana-accounts`, `khana-crm` or `khana-erp` as in the
README, and every artisan line is `sudo -u <name> php /var/www/<name>/current/artisan …`.

Nothing in a pilot touches the old monolith's database. The import below reads
a **copy** of it; the live `wwwwww_new` is never written to.

## 1. Wire the Suite link (once)

The README deploys three apps that can pass events. The Suite link is the other
half: each product signs in through Accounts, and reads the others' and
Accounts' APIs with a machine token Accounts issues. Set this up once, on top
of a green Gate G0.

1. **Mint the OAuth clients, on Accounts.** Re-runnable; it keeps existing
   clients and only updates scopes, so it is safe to run again after an upgrade.

   ```bash
   sudo -u khana-accounts php /var/www/khana-accounts/current/artisan khana:clients
   ```

   It prints, per product, a sign-in client id, a machine client id, and - the
   first time only - the machine secret. Keep the secrets; they are shown once.

2. **Give CRM and ERP Accounts' public key.** Each product checks a machine
   token on its own with Accounts' public key, so copy it to both:

   ```bash
   sudo cp /var/lib/khana-accounts/storage/oauth-public.key /etc/khana/accounts-public.key
   sudo chmod 644 /etc/khana/accounts-public.key
   ```

3. **Fill each product's `.env`** (`/var/lib/<name>/.env`), then `optimize`:

   | Key | Accounts | CRM | ERP |
   |---|---|---|---|
   | `KHANA_ACCOUNTS_URL` | - | `https://accounts.…` | `https://accounts.…` |
   | `KHANA_CRM_URL` / `KHANA_ERP_URL` | both | both | both |
   | `KHANA_ACCOUNTS_PUBLIC_KEY` | - | `/etc/khana/accounts-public.key` | same |
   | `KHANA_SSO_CLIENT_ID` | - | that product's sign-in id | same |
   | `KHANA_MACHINE_CLIENT_ID` / `_SECRET` | - | that product's machine pair | same |

   ```bash
   sudo -u <name> php /var/www/<name>/current/artisan optimize
   ```

4. **Check the token round-trip.** From CRM, read one of ERP's machine
   endpoints for an outlet that holds ERP; it should answer, and answer 401
   without a token. (The nightly checks below exercise this for real.)

## 2. Onboard the pilot restaurant

1. **In Accounts' browser:** sign the business up, create its outlet(s), and
   put it on a plan that includes **both** CRM and ERP. Signing up emits the
   reference events (tenant, outlet, entitlement) the other two need.

2. **Let the events land.** The scheduler delivers them within the minute; to
   not wait, `outbox:replay crm --from=1` and `outbox:replay erp --from=1` on
   Accounts, then `inbox:status` on CRM and ERP shows them applied. Each outlet
   now appears in both products, entitled.

3. **First menu sync.** An outlet that holds both products pulls its menu from
   CRM into ERP automatically when the entitlement lands. If the menu was built
   after that, use **Recipes → Pull the menu** in ERP, or wait for the nightly
   menu check.

4. **Build the day:** menu + prices in CRM; recipes + opening stock + suppliers
   in ERP. From here a sale in CRM moves stock in ERP, and a stock-out in ERP
   takes the dish off CRM's menu - live.

## 3. The gates

Each phase has a gate. G0–G3 are the README's and each app's own test suite; G4
and G5 are the pilot's.

| Gate | What it proves | How to check |
|---|---|---|
| G0 | The apps can hear each other | README step 5 (`events:ping`, `inbox:status`, `outbox:replay`) |
| G1 | Accounts stands alone | `artisan test` in `apps/accounts` |
| G2 | CRM stands alone | `artisan test` in `apps/crm` |
| G3 | ERP stands alone | `artisan test` in `apps/erp` |
| G4 | A lost event is healed overnight | below |
| G5 | A real day runs end to end | the checklist below |

### G4 — a deliberately dropped event is found and fixed

Prove the net under the event bus, both directions:

- **CRM side (sold-out):** take a dish off in ERP, then delete that
  `erp.availability.changed` from ERP's outbox before it is delivered (or just
  confirm CRM still shows the dish). Run `crm:nightly`. CRM reads ERP's
  `/signals` and takes the dish off - "Put right" - and raises a home-screen
  alert only if ERP could not be reached. A second run says "All in order".
- **ERP side (sales):** ring a bill in CRM, drop the `crm.sale` event, run
  `erp:nightly`. ERP fetches the bill from CRM and books its stock - "repaired".

`crm:nightly` also runs a **reference** check (CRM's outlets and entitlements
against Accounts) and `erp:nightly` its sales/menu/reference/balances checks.
Both are scheduled hourly, so in a running pilot G4 heals itself without anyone
running a command; the manual run is only to see it on demand.

### G5 — the pilot checklist

A day is good when, without anyone touching a database by hand:

- [ ] A guest orders from a QR table; the bill settles in CRM.
- [ ] That sale has moved the dish's ingredients in ERP by the next check.
- [ ] A dish whose stock ran out in ERP is off the menu in CRM (guest, POS, storefront).
- [ ] The food-cost tile and the sold-out list show on CRM's home screen for the outlet.
- [ ] A paid-out in CRM against an ERP supplier bill files itself.
- [ ] An outlet whose CRM is suspended in Accounts stops taking orders - that branch only.
- [ ] Last night's `crm:nightly` and `erp:nightly` are all `ok`/`repaired`, none `problem`, and no unresolved home-screen "nightly check" alert.
- [ ] The owner can sign in to all three products with one Accounts login.

## 4. Importing from the monolith

The old single app's data comes across **through Accounts**, never by writing
the split databases from the monolith directly, and never by touching the live
`wwwwww_new` - work from a dump.

1. **Dump a copy** of the monolith database and restore it somewhere isolated.
   All reading below is against that copy.
2. **Accounts first**, because it owns the ids. For each business create its
   tenant, its outlets (each gets a ULID - the id the other two know it by), its
   plan (CRM + ERP), and its people. This is the only import that writes
   Accounts; the outlet ULID it mints is the join key for everything else.
3. **Let CRM and ERP learn the businesses** the way they will in life: from
   Accounts' reference events, or a one-off `reference` snapshot pull. Do not
   insert `khana_outlets`/`khana_entitlements` by hand.
4. **CRM:** load the menu, prices, tables and customers, each shop keyed to its
   Accounts outlet ULID. **ERP:** load recipes, opening stock, suppliers and
   open bills per outlet; or pull the menu from CRM and add recipes against it.
5. **Reconcile, don't trust.** After a dry run, let `crm:nightly` and
   `erp:nightly` compare every outlet against Accounts and each other; a clean
   night - all `ok` - is the import's acceptance test. Fix the source mapping
   and re-run against the copy until a night is clean, then do it for real.

Numeric ids stay inside each service; ULIDs are the only ids that cross between
them. A mapping table from each monolith row to its new ULID, kept with the
import scripts, is what makes a re-run idempotent.
