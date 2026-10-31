# Restro One — Poora Project Manual (Hinglish)

Yeh document ek hi jagah batata hai ki project kya hai, har module kaise kaam karta hai, aur ab aage kya karna hai. Left menu ke order mein likha hai, taaki aap menu kholkar saath-saath padh sako.

> Yeh manual code, `docs/ERP-OVERVIEW.md`, config aur services padhkar likha gaya hai. Jo cheez maine khud browser mein chalakar nahi dekhi, wahan "code ke hisaab se" likha hai.

---

## 1. Project ek nazar mein

**Restro One** ek restaurant software hai jo SaaS ki tarah bechi jati hai: aap platform chalate ho, restaurants sign up karke trial lete hain, phir plan kharidte hain.

Ek restaurant ko isme yeh sab milta hai:

| Kaam | Kahan |
|---|---|
| Mez ka naksha, table, QR | Floor |
| Guest phone se order kare | QR scan → menu |
| Kitchen ko order dikhe | Kitchen |
| Counter par bill banana | Counter (POS) |
| Menu, stock, recipe | Operations → Menu & Stock |
| Maal kharidna | Operations → Purchasing |
| Paisa, kharcha, GST | Operations → Finance |
| Customer, udhaar, offers | Customers (CRM) |
| Hisab-kitab ki report | Insights → Reports |
| Company, plan, staff, settings | System |

**Technology:** Laravel (PHP) + MySQL. Admin panel aur public website dono isi project mein hain.

---

## 2. Teen level, aur kaun kya hai

```
Platform (aap, Super Admin)
  └── Company / Tenant  (ek business, jaise "Sharma Restaurants")
        └── Shop / Branch  (ek outlet, jaise "Sharma - Rooftop")
```

**Sabse bada rule:** har record ek shop ka hota hai, aur ek shop ka data doosre shop (ya company) ko kabhi nahi dikhta. Filter bhool bhi jao to list *khaali* aati hai, kisi aur ka data nahi.

### Roles (11)

| Role | Kaam |
|---|---|
| **Super Admin** | Poora platform: sab companies, plans, trial extend, suspend |
| **Admin** | Lagbhag sab, bas roles badalna nahi |
| **Tenant Owner** | Apni company: branches, staff, finance, reports |
| **Shop Admin** | Ek shop ka roz ka kaam |
| **Manager** | Nigrani, approvals. Delete/write-off nahi |
| **Cashier** | Sirf billing, payment, customer lookup |
| **Captain / Waiter** | Table par order lena, floor dekhna |
| **Kitchen Staff** | Kitchen screen, dish ready karna |
| **Accountant, Warehouse, Sales Executive, Auditor, Employee** | Apne-apne hisse ke hisaab se |

Do alag cheezein hain, aur dono "haan" bolein tabhi kaam hota hai:
- **Module on/off** (shop ke liye): kya yeh shop yeh kaam karta hai?
- **Permission** (insaan ke liye): kya yeh insaan yeh kar sakta hai?

Permission server par check hoti hai. Button chhupana kaafi nahi, seedha URL kholne par bhi roka jata hai.

---

## 3. Module-by-module

### Dashboard
Aaj ke aankde ek nazar mein: bikri, tables ka haal, orders, alerts. Upar ghanti (bell) mein alerts aate hain (low stock, till khula reh gaya). Bell ek manager ko apni saari branches ke alerts dikhati hai.

---

### FLOOR

**Dining Areas** — restaurant ke hisse (Ground Floor, Rooftop, Garden). Har area ka code hota hai. Ek shop mein code dobara nahi ban sakta.

**Tables** — har table ka naam, code, seats, status. Table banate hi uska **QR** apne aap ban jata hai. Table band (inactive) karo to plan se hat jati hai par uska itihaas rehta hai.
- Status 5 hain: **Available, Occupied, Reserved, Billing, Cleaning**.
- Status database mein *likha jata hai* (sirf nikala nahi jata), kyunki "safai ho rahi hai" ya "booking ke liye roka hai" kisi order se pata nahi chalta.
- Status badalne ki alag permission hai (`dining.tables.adjust`), taaki captain roz 40 baar party bitha sake par table ka naam/number na badal paye.

**Floor Plan** — live naksha. Detail alag se: section 4 mein.

**Table QR Codes** — har table ka ek hi live QR hota hai.
- Naya QR banane par purana **delete nahi hota, "revoked" hota hai**. Wajah: chhapa hua sticker mez par mahino rehta hai. Purana sticker scan karne par pata chalta hai ki woh wapas liya ja chuka hai, galat table par order nahi jata.
- QR link `/t/{token}` hota hai. Token hi secret hai, expire nahi hota (sticker "mar" na jaye).
- QR SVG mein banta hai, isliye chhapne par saaf rehta hai.
- Print sheet ek alag page hai.

**Reservations** — booking.
- Booking bina table chune bhi ban sakti hai. Table baad mein, us raat room dekhkar assign hota hai.
- **Double booking sabse badi galti hai**, isliye overlap check lock ke andar hota hai aur galti par *rok deta hai*, sirf warning nahi deta.
- Status: requested → confirmed → seated → completed. Ya no_show / cancelled.
- Booking Floor Plan ke tile par bhi dikhti hai (agle 1 ghante ki).

---

### KITCHEN

**Kitchen Display (KDS)** — kitchen ka screen.
- Har order ek **ticket** hai. Status: placed → accepted → preparing → ready → served.
- Status har **line (dish)** par rehta hai, poore ticket par nahi. Ticket ka status line ke sabse peeche wale status se nikalta hai. Kyunki alag-alag station par dishes alag time par ready hoti hain.
- Screen khud refresh hoti hai (polling). Naya ticket aane par ghanti bajti hai.
- Timer har baar ghadi se naapa jata hai, isliye raat bhar screen khuli rahe tab bhi sahi rehta hai.
- Aage badhana default hai. Peeche (recall) alag permission hai.
- **Kitchen Staff** ko menu edit permission nahi milti, bas "sold out" ka ek tap milta hai.

**Stations** — tandoor, bar, grill. Har dish ko station milta hai is order mein: dish ka apna station → category ka → category ke parent ka → shop ka default. Taaki koi dish "kisi ne nahi banayi" na rahe. Station order lagte hi line par copy ho jata hai.

**KOT slip** — kitchen ki parchi. Isme **price nahi** hota.

---

### COUNTER

**POS Billing** — counter ka till. Ek hi screen, ek hi endpoint.
- Items, quantity, discount, payment (cash/card/UPI/split), bill print.
- **Discount aur udhaar alag permission** hain (`pos.terminal.discount`, `pos.terminal.credit`). Cashier ke paas yeh nahi hoti. Matlab jo bill bana diya, usse badal nahi sakta.
- Bill ek **Invoice** hai (GST, number, ledger sab ek jaisa).

**Held Sales** — counter ka bill rok kar baad mein shuru karna (customer wallet lene gaya). Dine-in mein iski zarurat nahi, wahan *table hi hold hai*.

**Table Bills** — table ka bill.
- Ek **sitting** (party) ka ek bill. Sitting `open → billed → closed`.
- **Split:** item ke hisaab se, quantity ke hisaab se, ya amount ke hisaab se. Kai invoice ek sitting se bante hain.
- Sitting tabhi band hoti hai jab kuch baaki na ho. Table seedha Available nahi, **Cleaning** mein jata hai.
- **Take order** (captain ka pad): guest wale hi menu aur cart se. Nahi bhejne tak kuch pakta nahi aur kuch udhaar nahi.
- Kitchen mein khana baaki ho to screen bata deti hai par rokti nahi.
- **Bina paise ke table khali karna** (walkout) ke liye alag permission, likhi hui wajah, aur log. Bill ban chuka ho to yeh mana hai.
- Dish ka bill wahi price lagata hai jo guest ko dikhaya gaya tha (add-on samet).

**Day Close (Cash Register)** — din ka hisab.
- Subah opening float ke saath register kholo, shaam ko band karo.
- "Expected cash" us hisaab se aata hai jo pehle se `Payment` aur approved cash `Expense` mein likha hai. Yeh naya hisab nahi banata, bas milaata hai. Gintee aur expected ka farak dikhta hai.
- Register din ke baad khula reh jaye to alert aata hai.

**Barcode Labels** — shelf ke sticker. Kuch save nahi hota. Jo items aur copies chuno, utna print hota hai.

---

### OPERATIONS → Menu & Stock

**Menu Items** — menu aur products ek hi table hai (`products`).
- **Sizes** (Half/Full) ke apne poore price hote hain. Ek size hamesha default khulta hai.
- **Veg / non-veg / "khana nahi"** (paani ki bottle, bag, service charge): teen alag jawab hain.
- **Availability:** time window (raat 11 se 2 tak bhi chalta hai), aur **Sold out** ek tap mein. Sold out khud saaf ho jata hai (`sold_out_until`).
- **Channel price:** khaali = "wahi jo normal hai".
- Bulk import se menu ek saath daal sakte ho.

**Add-ons & Modifiers** — "How spicy?" jaise sawal.
- min/max se tay hota hai: 1/1 = zaroori chunav, 0/1 = optional, 0/koi = kitne bhi extras, 2/2 = theek do.
- Ek sawal kai dishes se judta hai.
- Browser par bharosa nahi hota: server size, add-on aur min/max dobara check karta hai.

**Recipes / BOM** — dish kis se bani hai.
- Ingredient bhi ek product hai (`is_ingredient`), menu mein nahi dikhta.
- Size ke hisaab se recipe alag ho sakti hai (Full mein zyada chawal). Size ka recipe likha ho to woh generic ko *badalta hai*, jodta nahi.
- Maatra ingredient ki apni unit mein, 4 decimal tak. Unit conversion nahi hai.
- **Stock kitchen mein ghat'ta hai, till par nahi**: jab dish "ready" (ya jo shop ne chuna) hoti hai. Ek line ke liye sirf ek baar, kabhi wapas nahi.
- Stock kam ho tab bhi consumption record hota hai (negative ho sakta hai). Yeh sach hai, aur low-stock alert isi ke liye hai.
- Screen par har dish ka cost, price aur margin dikhta hai. 50% se kam laal.

**Wastage** — kharab hua khana ka log. Stock ghatata hai, report mein aata hai.

**Stock on Hand** — kitna maal hai.
**Batches & Expiry** — lot aur expiry ke hisaab se stock. Expired maal bikri se bacha jata hai (shop ki setting ke hisaab se).
**Stock Adjustments** — gintee ke baad sudhaar. Delta nahi, *gina hua number* daalte ho, aur wajah zaroori hai.
**Stock Transfers** — do kadam: bheja → mila. Beech mein maal kisi ke paas nahi hota, isliye kam aaya to record hota hai.
**Warehouses** — storage jagah.
**Price Lists, Brands, Categories, Units** — masters.

**Stock ka pakka niyam:** `product_stocks` hamesha stock movements ke jod ke barabar rehta hai. Sirf `StockService` stock badalta hai. Kisi ne stock "edit" nahi kiya hota, har badlav ek record hai.

---

### OPERATIONS → Sales

**Invoices** — saare bill. Delete nahi hote, **cancel** hote hain: stock wapas, ledger ulta, payment void, number series mein rehta hai.
**Sales Returns** — maal wapas. Ek saath 4 kaam: theek maal shelf par (usi cost par jis par gaya), kharab maal write-off, invoice par wapsi likhi jaye, customer ko credit/refund.
**Live Orders** — abhi aa rahe orders (counter staff ke liye, kitchen ke screen se alag).
**Orders** — saare channel ka itihaas: dine-in, takeaway, delivery, counter. Channel filter hai.
**Coupons & Offers** — percent-off (cap ke saath) ya fixed amount, minimum order, kitni baar use ho sakta hai.

---

### OPERATIONS → Purchasing

Order → Receipt → Bill → Return.
- **Goods Receipt "post" karna** ek hi lamha hai jo ek saath: lot banata hai, stock shelf par, supplier ka khaata bharta hai, product ka price badalta hai (agar badla), order aage badhata hai. Sab ek transaction mein.
- **Freight** (dhulai/gaadi) items mein value ke hisaab se baant diya jata hai. Isse margin ghalat nahi dikhta.
- Free maal bhi stock hai aur average cost neeche laata hai.
- **Suppliers** — supplier ka khaata (payable).
- **Purchase Returns** — maal supplier ko wapas.

---

### OPERATIONS → Finance

**Payments** — har payment. Edit nahi hoti. Galti ho to ulta (reversing) payment banta hai. Cheque tab tak "pending" rehta hai jab tak clear na ho. Sirf cleared payment balance ghatata hai.
**Online Payments, Refunds, Settlement** — gateway se payment, wapsi, aur settlement. Gateway ka key lagne ke baad chalta hai.
**Expenses** — kharcha.
**Tax / GST Setup** — rates. Invoice par rate aur CGST+SGST/IGST ka batwara **bill ke samay hi** likh diya jata hai, baad mein address badle to purana bill nahi badalta.
**Chart of Accounts** — menu mein hai par **banaya nahi gaya** (placeholder). Uski jagah **Tally export** hai.

**Paise ke niyam:**
- 2 decimal har kadam par, final total nazdeeki rupaye mein, farak `round_off` mein.
- Invoice ka discount lines par value ke hisaab se baanta jata hai, total se ghataya nahi jata (GST sahi rehta hai).
- Tax-inclusive price se pehle tax alag kiya jata hai, do baar nahi lagta.
- Cost bikri ke *samay ka* average hota hai. Baad mein maal mehnga aaye to purane mahine ka margin nahi badalta.

---

### CUSTOMERS → CRM

**Customer Master** — customer ki list.
**Customer Ledger / Credit & Dues** — udhaar. `customers.balance` hamesha ledger ki aakhri line ke barabar rehta hai.
**Loyalty Points** — balance kabhi ek column nahi, **rows ka jod** hai. Isliye balance aur statement kabhi alag nahi ho sakte. Points milna bill ko kabhi fail nahi karta, par points *kharch karna* galti par rok deta hai.
**Campaigns** — ek message bahut logon ko. Bhejne se pehle audience **freeze** ho jati hai. Bhejna dheere, ek batch ek baar, scheduler se hota hai.
**Guest Feedback** — guest ki rating.
**Payment Reminders** — overdue bill par yaad dilana. Do hisse: `schedule` (kya kehna hai likho) aur `dispatch` (bolo). Do baar kabhi nahi jata. Jisne kal paya, use aaj nahi dhoondha jata (bhejne ke samay check hota hai).

---

### INSIGHTS → Reports

Ek hi jagah (`ReportService`) saari reports. Niyam: draft aur cancelled invoice bikri nahi, sirf cleared payment collected, cost bikri ke samay ka.

Reports: Sales, Profit, Product Sales, Sales by table/employee/shop, Discounts, Coupons, Cancelled orders, Customer history, Payment methods, Tax/GST (HSN), Input tax, Purchases, Stock valuation, Low stock, Expiry, Outstanding & Dues, Supplier payables, Returns, Financial summary, Stock transfers, Day closing, Kitchen performance.

Export: CSV (Excel mein khulta hai), PDF browser se. **Export to Tally**: vouchers jo Tally import kar leta hai.

---

### SYSTEM

**Companies** *(Super Admin)* — companies. **Suspend** = staff turant band, data safe. Delete tabhi jab koi branch na ho. Kam se kam ek company active rehni chahiye.

**Shops** — branches. Har shop khud chunta hai ki kaun-kaun se module chalu hon. Naya shop kabhi chuna nahi gaya ho to "sab chalu" mana jata hai.

**Subscription** — company ka apna plan aur kitne din bache. **Subscriptions** (Super Admin) — kisi company par plan lagana, payment record karna, cancel/resume, **trial extend (1–90 din)**. Plan **kab tak chalega** dates se roz nikalta hai (cron par nirbhar nahi). Grace period mein dukaan chalti rehti hai par banner aata hai. Plan ki limit: shops kitne, kaun se modules. **Plans** — price list.

**Messages** *(naya)* — aapas mein private chat. Same company ke log, aur platform admin. Text database mein encrypted hai (end-to-end nahi). Sirf do participants dekh sakte hain.

**Support** — restaurant ticket banata hai, platform team jawab deti hai.
- Ticket company ka hota hai, shop ka nahi.
- Doosri company ka ticket kholo to **404** aata hai (403 nahi), kyunki "yeh ticket hai par tumhara nahi" kehna bhi leak hai.
- **Internal note** sirf platform team ko dikhta hai.
- Status khud badalta hai (restaurant ne likha → `awaiting_support`, desk ne → `awaiting_customer`).
- Resolved ticket par jawab aaye to dobara khulta hai. Closed par nahi.

**Printers & Devices / Print Log** — printer. Print fail hone par kabhi order/bill nahi rukta, bas `failed` job likha jata hai. Station ka printer → shop ka default KOT printer → browser print.
**API Tokens** — aggregators ke liye. Token ek baar dikhta hai.
**Firebase Notifications** — push.

**Settings:**
- **Users** — staff, role, shops.
- **Roles & Permissions** — 321 permissions, matrix se.
- **Activity Logs** — kisne kya kab kiya.
- **System Health** — server ka haal (sirf platform ke liye). Fail hue jobs ko dobara chalana alag permission hai.
- **General** — company, mail, payment settings.

**Aur** (menu ke dusre hisse): **Online Store** (sliders, services, collections, events, blog, Instagram), **Landing Page** (trust numbers, testimonials, screenshots, demo requests, site pages, FAQs), **Email Templates / Logs**.

---

## 4. Floor Plan kaise kaam karta hai

- Har table ek tile hai. Jagah `pos_x`, `pos_y` mein **percentage** mein save hoti hai, isliye har screen par naksha ek jaisa dikhta hai.
- Tile par: naam, seats, status, party kitni der se baithi (min), agli booking, "Bill" button, status dropdown.
- **Rang:** Available hara, Occupied peela, Reserved neela, Billing brand-colour, Cleaning bina rang.
- **Status party se chalta hai:** QR scan → Occupied; bill → Billing; payment → Cleaning; safai ke baad aap Available karte ho.
- Party baithi ho to table ko haath se Available/Cleaning/Reserved nahi kar sakte. Server mana kar deta hai.
- **Edit layout** dabao tabhi tile ghoomte hain. Chhodte hi position save.
- **Live:** har 10 second khud refresh. Drag, popup ya dropdown ke samay ruk jata hai.
- **Alerts (laal ghera):** 90+ min baithe, bill 10+ min se baaki, cleaning 15+ min, ya booking wala guest 10+ min late. Limits `config/dining.php` mein badal sakte ho.
- "Bill waiting" aur "Cleaning slow" ka time andaaza hai, kyunki database mein inka alag time nahi hota.

---

## 5. Ek order ka poora safar (QR se)

1. Guest sticker scan karta hai → **sitting** khulti hai, table Occupied.
2. Table ke saare phones ka **cart ek hi** hota hai.
3. Order bhejne par ek **ticket** banta hai, station par jata hai, kitchen screen par aata hai.
4. Kitchen status badalti hai, guest ke phone par live dikhta hai.
5. Dish ready hone par recipe ke hisaab se **ingredients stock se ghatte hain**.
6. Captain pad se aur dishes bhej sakta hai.
7. Counter bill banata hai (poora ya split), payment leta hai.
8. Sitting band, table Cleaning, phir Available.
9. Bikri, GST, ledger, food cost, stock sab isi ek sequence se badalte hain.

Guest ke pages mein JavaScript ke bina bhi kaam chalta hai (sirf live status JS se).

---

## 6. Apne aap chalne wale kaam (scheduler)

| Kab | Kaam |
|---|---|
| Roz 03:45 | Database backup (14 din rakhta hai) |
| Roz 07:30 | Subscription sweep: expire hui company ko flag karta hai |
| Roz 07:45 aur 15:00 | Alerts: low stock, khule reh gaye till |
| Roz 08:30 | Payment reminders likhna |
| Roz 09:00 | Subscription reminders |
| Har 30 min (09–19) | Reminders bhejna |
| Har ravivaar 03:15 | Purane email logs (90 din) saaf |
| Campaigns | Batch mein bhejna |

**Zaroori:** server par Laravel ka scheduler chalna chahiye (`php artisan schedule:run` har minute). Aur queue worker chalna chahiye (`QUEUE_CONNECTION=database`).

---

## 7. Paisa aur stock ke pakke niyam (jinhe todna nahi)

1. **Kuch delete nahi hota, ulta hota hai.** Invoice cancel, payment ka reversing entry, stock ka naya movement.
2. **Har cheez ka ek hi darwaza.** Stock → `StockService`. Customer balance → `LedgerService`. Invoice → `InvoiceService`. Receipt → `PurchaseService`.
3. **Sab kuch transaction aur row lock mein.** Do cashier ek hi customer ko ek second mein bill karein to bhi sahi.
4. **Server browser par bharosa nahi karta.** Size, add-on, min/max sab dobara jaanchta hai.
5. **Doosre shop ka data kabhi nahi.** Galti hui to khaali list.

---

## 8. Security (kya hai, kya nahi)

**Hai:**
- Role + permission, server par check.
- Tenant isolation ke tests, cross-tenant access ke tests.
- Suspended company ka staff turant bahar.
- Login par rate limit, 5 galti par lockout, OTP par bhi.
- XSS se bachav (output escape), SQL injection nahi mila (raw queries mein bound values / fixed strings).
- Security headers (nosniff, clickjacking, referrer, permissions, HSTS HTTPS par).
- Production mein kamzor Super Admin password refuse. Staff ka password production mein 10+ aur mixed case aur number.
- `php artisan security:audit` deploy se pehle ka checklist.
- Messages database mein encrypted.

**Nahi hai (seedhi baat):**
- Messages **end-to-end** encrypted nahi hain.
- Content-Security-Policy nahi lagayi (inline scripts bahut hain, tod deti).
- Kisi alag security expert se pen-test nahi hua.

---

## 9. Ab kya karna hai (order mein)

### A. Client ko dene se pehle
1. `php artisan backup:run`
2. `php artisan migrate` (Messages ki 2 tables)
3. `php artisan permissions:sync`
4. Super Admin ka password badlo (abhi `password` hai)
5. Har role ka test user banao
6. Sample data: 2 areas, 6+ tables, 10+ menu items, 1 station
7. Khud scenario A (dine-in) aur D (trial/suspend) chala kar dekho
8. `php artisan security:audit` — koi FAIL nahi
9. Client ko [CLIENT-TESTING-GUIDE.md](CLIENT-TESTING-GUIDE.md) do

### B. Live jaane se pehle (`.env`)
`APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://...`, `SESSION_SECURE_COOKIE=true`, `LOG_LEVEL=warning`, `ADMIN_EMAIL` aur strong `ADMIN_PASSWORD`. Mail, payment gateway, Firebase, SMS/WhatsApp ke keys.

### C. Baad mein (abhi nahi bana)
- **Chart of Accounts** (Tally export hai uski jagah)
- Captain app, kiosk, TV menu board, offline POS, aggregator integration (SRS mein bhi "future" hai)
- Floor plan: table ka shape/size/rotate, table merge/split, waiter assign (database badlav chahiye)
- Messages: instant (WebSocket) delivery; abhi har 3 second mein check hota hai
- Floor plan "bill waiting" / "cleaning" ke exact timestamp ke liye naye columns

### D. Jo abhi tak verify nahi hua
- Maine UI (modal, floor plan, shops form) browser mein khol kar nahi dekha. Pehle aap dekh lo, phir client.
- Poori test suite pass hai (1838 tests), par tests CSS/look nahi parakhte.
- Performance ka koi before/after number nahi naapa gaya.

---

## 10. Kuch shabd

| Shabd | Matlab |
|---|---|
| Tenant | Company/business |
| Shop | Branch/outlet |
| Sitting (table session) | Ek party ka table par baithna, jisse ek bill jura hai |
| KOT | Kitchen Order Ticket, kitchen ki parchi |
| KDS | Kitchen Display System, kitchen screen |
| FEFO | Jo pehle expire ho, woh pehle bikta hai |
| Ledger | Khata (customer/supplier) |
| Landed cost | Maal ka asli kharcha: price + freight |
| Idempotent | Do baar chalane par bhi do baar nahi hota |
| Grace period | Plan khatam hone ke baad kuch din ki chhoot |
