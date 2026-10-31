# Restro One — Client Testing Guide

This guide walks you through every part of the system in the order of the left-hand menu: what it is for, how to try it, and what you should see. Tick each line as you go. If something does not match, use the bug template at the end.

---

## 0. Before testing starts (for the project team)

| Done | Step |
|---|---|
| ☐ | Run `php artisan migrate` (adds the Messages tables) |
| ☐ | Run `php artisan permissions:sync` (adds the Messages permission) |
| ☐ | Change the default Super Admin password (it ships as `password`) |
| ☐ | Create one test user per role in section 2 (Settings → Users) |
| ☐ | Make sure the test company has: 1 shop, 2 dining areas, 6+ tables, 10+ menu items, 1 kitchen station |
| ☐ | Run `php artisan security:audit` and fix every FAIL before going live |

Test on a copy, not on live data.

---

## 1. How the system is organised

Three levels:

| Level | What it is | Who sees it |
|---|---|---|
| **Platform** | The whole service | Super Admin only |
| **Company** | One business (may have many branches) | The Company's Tenant Owner |
| **Shop** | One branch/outlet | Staff of that branch |

**The key rule:** a shop's data is private to that shop. One company can never see another company's data. A good test is to log in as two different companies and confirm neither can see the other's tables, menu, bills or customers.

Each shop can switch whole areas on or off (Dine-in, Kitchen, POS, Inventory, Purchase, Customers, Reports...). A switched-off area disappears from the menu and cannot be opened by typing its address either.

---

## 2. Roles to test with

| Role | Should be able to | Should NOT be able to |
|---|---|---|
| **Super Admin** | Everything, across all companies; suspend/reinstate companies; plans; extend trials | — |
| **Tenant Owner** | Everything inside their own company: branches, staff, finance, reports | Platform settings, other companies, editing their own subscription term |
| **Manager** | Monitor sales, stock, staff, approvals | Delete, write off, settings |
| **Cashier** | POS billing, payments, customer lookup | Discount override, credit, refund, edit past bills |
| **Captain / Waiter** | Take orders at a table, see the floor | Settle money |
| **Kitchen Staff** | Kitchen display, mark dishes ready | Billing, settings |

For each role, sign in and check: (a) the menu shows only what the role should have, (b) typing the address of a hidden page gives "access denied", not the page.

---

## 3. Module by module

### Main → Dashboard
**What:** Today's numbers at a glance (sales, tables, orders).
**Test:** Open it after creating a few bills; the figures should move. Compare one total with Reports → Sales.

---

### Floor

**Dining Areas** — the rooms or zones (Ground Floor, Rooftop).
- Add an area. Rename it. Try adding the same code twice in one shop (should be refused).

**Tables** — each table has a name, code, and seats.
- Add a table; a QR code is issued for it automatically.
- Switch a table off — it should disappear from the plan but keep its history.

**Floor Plan** — a live map of the room.
- Each table is a coloured tile: green = available, amber = occupied, blue = reserved, orange = billing, plain = cleaning.
- Tile shows seats, status, how long the party has been seated, the next booking, and a **Bill** button when a party is seated.
- Change a status from the dropdown on the tile.
- **Edit layout** button: press it, drag tiles to rearrange, press **Done editing**. Layout is locked otherwise, so a stray drag cannot move tables during service.
- The page refreshes itself about every 10 seconds ("Updated hh:mm:ss" at the top). Seat a party on another screen and watch this one change.
- Red outline on a tile = needs attention (seated 90+ min, bill waiting 10+ min, cleaning 15+ min, or booked guest 10+ min late). The top line counts them.
- **Must-pass check:** a table with a party seated cannot be set to Available / Cleaning / Reserved by hand — it should refuse and tell you to settle the bill first.

**Table QR Codes** — print the QR stickers.
- Open the print sheet; each QR should open that table's menu when scanned with a phone.

**Reservations** — bookings.
- Create a booking for 30 minutes from now on a table. It should appear on that table's tile. Mark it seated / cancelled / no-show and see the tile follow.

---

### Kitchen

**Kitchen Display** — the screen the cooks watch.
- Place an order (below) and watch the ticket appear within seconds.
- Move the dish through Accepted → Preparing → Ready. The guest's phone should show the same stage.

**Stations** — tandoor, bar, grill... Each menu item is routed to a station.
- Make two stations, send an order with dishes for both, and confirm each station sees only its own dishes.
- A kitchen slip (KOT) shows **no prices**.

---

### Counter

**POS Billing** — the till.
- Add items, change quantity, apply a discount (only roles allowed to), take payment (cash / card / UPI / split), print the bill.
- Check tax is shown correctly and the total rounds to the nearest rupee.

**Held Sales** — park a counter bill and resume it later.
- Hold a bill, start another, resume the first one.

**Table Bills** — the bill for a table's sitting.
- Open a table with a party. See all rounds ordered so far.
- **Take order** (captain with a pad): pick dishes, press **Send** — the kitchen sees it.
- Split the bill by item, by quantity, or by amount. The table closes only when nothing is left to pay, then goes to **Cleaning**.
- If food is still in the kitchen when the bill is raised, the screen warns but lets you continue.
- Clearing a table **unpaid** needs a special permission and a written reason; it is refused once anything has been billed.

**Day Close** — end-of-day cash register.
- Open a register with an opening float, take cash payments, close it. The expected vs counted difference should be shown.

**Barcode Labels** — print labels for items.

---

### Menu & Stock

| Screen | Test |
|---|---|
| **Menu Items** | Add a dish with a price and tax; add sizes (each with its own price); mark one **sold out** with one tap and confirm guests can no longer order it |
| **Categories / Brands / Units** | Add and edit; confirm they appear when adding a dish |
| **Price Lists** | Create a different price for a channel (e.g. delivery); blank means "same as normal" |
| **Add-ons & Modifiers** | Create a question ("How spicy?", required) and attach it to a dish; ordering without answering should be refused |
| **Recipes / BOM** | Define what ingredients one dish uses |
| **Wastage** | Record spoiled food; it reduces stock and appears in reports |
| **Stock on Hand** | Check quantities; after selling a dish with a recipe, ingredient stock should fall (the kitchen consumes, not the till) |
| **Batches & Expiry** | See stock by batch and expiry date |
| **Stock Adjustments** | Correct a count — creates a movement record, never overwrites |
| **Stock Transfers** | Move stock between warehouses/shops |
| **Warehouses** | Add a storage location |

Rule to verify: stock is never edited directly — every change leaves a record you can trace.

---

### Sales

- **Invoices** — all bills. Open one; print it. **Cancel** an invoice: stock returns, payment is voided, the bill number stays (you cannot delete it).
- **Sales Returns** — return items against an invoice; stock and money are reversed.
- **Live Orders** — orders arriving now, from all channels.
- **Orders** — full history with a channel filter (dine-in, takeaway, delivery, counter).
- **Coupons & Offers** — create a coupon and apply it at billing; try an expired one (should be refused).

---

### Purchasing

Flow: **Purchase Order → Goods Receipt → Purchase Invoice → Purchase Return**.
- Create a supplier, raise an order, receive the goods. Stock should rise.
- Add freight on a receipt — it is spread across the items, raising their cost.
- Post a receipt: after posting it cannot be quietly edited.
- Return goods to a supplier: stock goes down, supplier balance changes.

---

### Finance

- **Payments** — every payment taken. A cheque stays "pending" until cleared; only cleared payments reduce a balance.
- **Online Payments / Refunds / Settlement** — gateway payments, their refunds, and settlements (needs the payment gateway configured).
- **Expenses** — record a cost; see it in the financial summary.
- **Tax / GST Setup** — rates. Change a rate and bill again; old invoices must not change.

---

### CRM (Customers)

- **Customer Master** — add a customer.
- **Customer Ledger / Credit & Dues** — sell on credit; the balance must match the last ledger line. Record a payment and see dues fall.
- **Loyalty Points** — points earned on bills and redeemed.
- **Campaigns** — send a message to a customer group.
- **Guest Feedback** — what guests rated after dining.
- **Payment Reminders** — automatic reminders for overdue bills; a customer who has already paid must not be chased.

---

### Online Store and Website content

Sliders, Services, Collections, Events, Blog, Instagram, Landing Page items, Site Pages, FAQs, Demo Requests.
- Edit a slider or page and confirm the public website changes.
- Submit the **Book a demo** form on the public site: it should thank the visitor on the Demo page and appear under **Demo Requests**.

---

### Email

- **Email Templates** — edit the wording of system emails; send a test.
- **Email Logs** — every email sent, with its status.

---

### Reports

Open **All reports**, then: Sales, Profit, Product Sales, Purchases, Stock & Valuation, Low Stock, Expiry, Outstanding & Dues, Payment Methods, Tax/GST, Stock Transfers, Supplier Payables, Day Closing, Returns & Refunds, Financial Summary, Export to Tally.
- Pick a date range; compare a total with the matching list screen (e.g. Sales report vs Invoices).
- Export and open the file.

---

### System

**Companies** *(Super Admin)* — add a company; **suspend** it: its staff must be signed out and blocked at once; **reinstate** it and they can sign in again. Its data is kept throughout.

**Shops** — add a branch. Open **Add Shop**: Identity is open; other sections (Tax, Contact, Billing, Modules, QR ordering, Staff access, Presentation) open with a click. Turn modules on/off and check the menu changes.

**Subscription** *(company's own view)* — plan, days left, payments. A company must not be able to extend its own term.

**Subscriptions / Plans** *(Super Admin)* — assign a plan, record a payment, cancel/resume. **Extend trial**: open a company's subscription, enter days (1–90) and press **Extend trial**; the new end date shows at once. After the end date passes, the company is refused (billing page only, if it can pay online).

**Messages** — private chat.
- Pick a colleague, send a message; the other person sees it within a few seconds without refreshing.
- Same message never appears twice; "read" appears once the other person opens it.
- You can message people in your own company, and the platform admin; you cannot message another company.
- Nobody except the two people in a conversation can open it — not even the Super Admin.
- Messages are encrypted in the database. They are **not** end-to-end encrypted (the server can decrypt them).

**Support** — a restaurant raises a ticket; the platform team replies. Internal notes are visible to the platform team only.

**Printers & Devices / Print Log** — add a printer, print a bill and a kitchen slip, check the log.
**API Tokens** — create a token; it is shown once only.
**Firebase Notifications** — push notification setup.

**Settings → Users** — add a user, assign a role and shops; deactivate one and confirm they are signed out. Passwords: 8+ characters (10+, mixed case and a number in production).
**Settings → Roles & Permissions** — change a role's permissions; the effect is immediate.
**Settings → Activity Logs** — every important action (who, what, when).
**Settings → System Health** — backups and server checks.
**Settings → General** — company/brand details, mail, payments.

---

## 4. End-to-end scenarios (do these in full)

**A. A dine-in guest**
1. Seat a party at a table (scan its QR with a phone). Floor Plan: table turns Occupied, timer starts.
2. Guest orders two dishes from the phone. Kitchen Display shows the ticket.
3. Cook marks it Ready; guest's phone shows Ready.
4. Captain adds a drink from Take order; it goes to the right station.
5. Cashier opens Table Bills, splits the bill in two, takes cash on one and UPI on the other.
6. Table goes to Cleaning, then you set it Available.
7. Check: invoices exist, stock fell, Reports → Sales includes it.

**B. Counter sale with return**
1. POS bill with 3 items and a discount. 2. Print. 3. Return one item from Sales Returns. 4. Check stock, payment and the day-close total.

**C. Buying stock**
1. Supplier → Purchase Order → Goods Receipt with freight → Purchase Invoice. 2. Stock on Hand rose. 3. Return half to the supplier. 4. Supplier Payables report matches.

**D. Trial and suspension**
1. Super Admin creates a company on a 7-day plan. 2. Extend trial by 5 days; end date moves by 5. 3. Suspend the company; its owner cannot sign in. 4. Reinstate; they can. 5. The owner tries to extend their own trial — refused.

**E. Two companies stay separate**
1. Sign in as Company A, note a table, a menu item, a customer. 2. Sign in as Company B — none of those appear. 3. Try to open A's page by address from B — refused.

---

## 5. Things every screen should do

- Forms show red messages next to the wrong field and keep what you typed.
- Saving does not reload the whole page; the list updates.
- Pressing Save twice does not create two records.
- A button you do not have permission for is hidden **and** refused if you reach it by address.
- Works on a phone-sized window: no sideways scrolling, popups fit the screen, buttons are easy to tap.
- Losing the internet shows a clear message rather than failing silently.

---

## 6. Notes for testers

- The floor-plan "Bill waiting" and "Cleaning is slow" timers are close estimates, not exact: they run from the last recorded activity.
- Messages arrive by checking every few seconds, not instantly.
- Some screens need outside services to be set up first (online payments, SMS/WhatsApp, push notifications, email). If they are not configured, "not configured" is the correct result.
- Do not use real customer or card data in testing.

---

## 7. Reporting a bug

| Field | Example |
|---|---|
| Screen / address | Floor Plan — `/admin/tables/plan` |
| Signed in as (role) | Cashier |
| What you did, step by step | 1. Opened table 5 … 2. Pressed Bill … |
| What you expected | Bill screen opens |
| What happened | Error message "…" (attach screenshot) |
| Device / browser | Android phone, Chrome |
| Date and time | 28 Oct, 14:32 |
