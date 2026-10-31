# Demo companies and logins

Four companies (two on Starter, one each on Restaurant and Chain), filled with sample data by `database/seeders/DemoTenantsSeeder.php`. Everything is made-up: names, GSTINs, phone numbers, e-mail addresses (`*.example`), prices.

**Staff password for every account below: `Demo@12345`** (change it before sharing the server outside your network).
Sign in at `http://localhost/archive/public/login`.

The company owners are the sign-ups that already existed; their own passwords were not touched.

| Plan | Company | Owner login (existing password) | Trial ends |
|---|---|---|---|
| Starter | Chai Point Corner (Bengaluru) | admin@gmail.com | see Subscription screen |
| Starter (second) | Bean & Bite Cafe (Hyderabad) | aaaaaa@gmail.com | see Subscription screen |
| Restaurant | Spice Route Kitchen (Pune) | shiva420466@gmail.com | see Subscription screen |
| Chain | Urban Tadka (3 branches) | tttttttttt@gmail.com | see Subscription screen |

## Staff accounts

### Starter: Chai Point Corner
| Role | Name | Email |
|---|---|---|
| Manager | Neha Kulkarni | neha.kulkarni@chai-point-corner.example |
| Cashier | Rohit Nair | rohit.nair@chai-point-corner.example |

### Starter (second): Bean & Bite Cafe
| Role | Name | Email |
|---|---|---|
| Manager | Aisha Siddiqui | aisha.siddiqui@bean-bite-cafe.example |
| Cashier | Sai Kiran | sai.kiran@bean-bite-cafe.example |

### Restaurant: Spice Route Kitchen
| Role | Name | Email |
|---|---|---|
| Manager | Aditya Joshi | aditya.joshi@spice-route-kitchen.example |
| Cashier | Pooja Deshmukh | pooja.deshmukh@spice-route-kitchen.example |
| Cashier | Sameer Khan | sameer.khan@spice-route-kitchen.example |
| Captain / Waiter | Rohan Pawar | rohan.pawar@spice-route-kitchen.example |
| Captain / Waiter | Ishita Kulkarni | ishita.kulkarni@spice-route-kitchen.example |
| Kitchen Staff | Imran Shaikh | imran.shaikh@spice-route-kitchen.example |
| Kitchen Staff | Vikram Gaikwad | vikram.gaikwad@spice-route-kitchen.example |

### Chain: Urban Tadka
| Branch | Manager | Cashier | Captain | Kitchen |
|---|---|---|---|---|
| Andheri West (Mumbai) | kunal.mehta@ | divya.shetty@ | arun.yadav@ | suresh.naik@ |
| Baner (Pune) | mrunal.kale@ | tejas.bhosale@ | pranav.jagtap@ | ajay.thorat@ |
| Koramangala (Bengaluru) | shreya.hegde@ | naveen.gowda@ | karthik.rao@ | basavaraj@ |

(all at `@urban-tadka.example`)

## What each company has

| | Starter | Restaurant | Chain |
|---|---|---|---|
| Menu | 33 items: chai, coffee, shakes, snacks, bakery, packaged | 34 dishes (sizes, add-ons) + 17 ingredients | 25 items (sizes) + 12 ingredients, same menu in every branch |
| Stock | 16 tracked items | ingredients and drinks, perishables in batches (one lot near expiry) | each branch has its own stock |
| Tables / QR | none (not in plan) | 14 tables, 2 floors, 3 sitting now, 5 bookings | 6 + 5 + 5 tables, 4 sitting now |
| Kitchen stations | none | Tandoor, Main Kitchen, Bar & Desserts | Main Kitchen + Beverages per branch |
| Recipes | none | 12 dishes (by size) | 8 dishes, in every branch |
| Bills | about 49 over three weeks | about 70 | about 160 across branches |
| Customers / suppliers | 14 / 3 | 14 / 5 | 14 / 4 per branch |

Plan limits are respected: Starter has no Inventory, Dine-in or Kitchen screens (they show "Access denied" by design).

## Known limits

- No pictures: items have no photos yet.
- The seeder skips a company that already has a menu, so running it again is safe.
