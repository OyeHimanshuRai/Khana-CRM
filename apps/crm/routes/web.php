<?php

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\AlertController;
use App\Http\Controllers\Admin\BarcodeLabelController;
use App\Http\Controllers\Admin\BatchController;
use App\Http\Controllers\Admin\BillingController;
use App\Http\Controllers\Admin\CashRegisterController;
use App\Http\Controllers\Admin\BlogController;
use App\Http\Controllers\Admin\ApiTokenController;
use App\Http\Controllers\Admin\BrandController;
use App\Http\Controllers\Admin\CampaignController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\CollectionController;
use App\Http\Controllers\Admin\CompanySettingController;
use App\Http\Controllers\Admin\CouponController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\CustomerLedgerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\EmailLogController;
use App\Http\Controllers\Admin\EmailTemplateController;
use App\Http\Controllers\Admin\EventController;
use App\Http\Controllers\Admin\ExpenseController;
use App\Http\Controllers\Admin\FeedbackController;
use App\Http\Controllers\Admin\FloorController;
use App\Http\Controllers\Admin\LoyaltyController;
use App\Http\Controllers\Admin\KitchenController;
use App\Http\Controllers\Admin\LiveOrderController;
use App\Http\Controllers\Admin\RecipeController;
use App\Http\Controllers\Admin\WastageController;
use App\Http\Controllers\Admin\TableBillController;
use App\Http\Controllers\Admin\KitchenStationController;
use App\Http\Controllers\Admin\RestaurantTableController;
use App\Http\Controllers\Admin\TableQrController;
use App\Http\Controllers\Admin\FaqController;
use App\Http\Controllers\Admin\GoodsReceiptController;
use App\Http\Controllers\Admin\InstagramPostController;
use App\Http\Controllers\Admin\InvoiceController;
use App\Http\Controllers\Admin\ReelController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\SalesReturnController;
use App\Http\Controllers\Admin\LoginController;
use App\Http\Controllers\Admin\OnlineOrderController;
use App\Http\Controllers\Admin\PasswordResetController;
use App\Http\Controllers\Admin\PasswordSetupController;
use App\Http\Controllers\Admin\ModifierController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\PaymentReminderController;
use App\Http\Controllers\Admin\PosController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\OnlinePaymentController;
use App\Http\Controllers\Admin\PlanController;
use App\Http\Controllers\Admin\PriceListController;
use App\Http\Controllers\Admin\PrinterController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Admin\PurchaseInvoiceController;
use App\Http\Controllers\Admin\PurchaseOrderController;
use App\Http\Controllers\Admin\PurchaseReturnController;
use App\Http\Controllers\Admin\ReservationController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\ScannerController;
use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\Admin\ShopController;
use App\Http\Controllers\Admin\ShopSwitchController;
use App\Http\Controllers\Admin\SliderController;
use App\Http\Controllers\Admin\StockAdjustmentController;
use App\Http\Controllers\Admin\StockController;
use App\Http\Controllers\Admin\StockTransferController;
use App\Http\Controllers\Admin\SubscriptionController;
use App\Http\Controllers\Admin\SupplierController;
use App\Http\Controllers\Admin\DemoRequestController as AdminDemoRequestController;
use App\Http\Controllers\Admin\IntegrationController;
use App\Http\Controllers\Admin\LandingStatController;
use App\Http\Controllers\Admin\OutletTypeController;
use App\Http\Controllers\Admin\ShowcaseController;
use App\Http\Controllers\Admin\SupportTicketController;
use App\Http\Controllers\Admin\TestimonialController;
use App\Http\Controllers\Admin\SystemHealthController;
use App\Http\Controllers\Admin\TaxRateController;
use App\Http\Controllers\Admin\TenantController;
use App\Http\Controllers\Admin\TenantSwitchController;
use App\Http\Controllers\Admin\UnitController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\UserSecurityController;
use App\Http\Controllers\Admin\WarehouseController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\TableFeedbackController;
use App\Http\Controllers\DemoRequestController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\SeoController;
use App\Http\Controllers\SignupController;
use App\Http\Controllers\TableManifestController;
use App\Http\Controllers\TableOrderController;
use App\Http\Controllers\TablePaymentController;
use App\Http\Controllers\TableVerifyController;
use App\Http\Controllers\TableScanController;
use Illuminate\Support\Facades\Route;

/*
| The public landing page.
|
| Was Laravel's stock welcome view. It is now a rendering of the admin panel's
| Content section - sliders, services, the menu, collections, events, blog,
| Instagram and FAQs - with the name, logo, address, hours and social links
| coming from Company Settings.
|
| Deliberately outside every auth and tenant middleware: nobody is signed in
| when a guest opens the front page. See App\Http\Controllers\LandingController
| for why that is safe, and for the one model it must not read because of it.
*/
Route::get('/', LandingController::class)->name('landing');

/*
| "Book a demo" (SS19).
|
| The only public write on this site outside the table-QR flow, so it is rate
| limited: six posts an hour from one IP is far more than any honest visitor
| needs and far less than a script wants. The honeypot inside the controller is
| the cheaper first line; this is what stops whatever ignores it.
*/
Route::post('demo-request', [DemoRequestController::class, 'store'])
    ->middleware('throttle:6,60')
    ->name('demo-request.store');

/*
| Opening an account (§21).
|
| The other half of the pricing section: the card says what a plan costs and
| this is where somebody buys it, without waiting for an administrator to
| build their company, their branch, their login and their subscription by
| hand. See App\Http\Controllers\SignupController.
|
| `guest`, because somebody already signed in has an account - the middleware
| sends them to their own dashboard rather than letting them open a second one
| by refreshing an old tab.
|
| The POST is rate limited harder than the demo form is, and for a different
| reason: this one writes four rows and a login, so a script left running
| overnight is a database full of businesses rather than an inbox full of
| enquiries. The honeypot inside the controller is the cheaper first line;
| this is what stops whatever ignores it.
|
| The `signup` limiter is named rather than written here as numbers, because
| what is right in production (five an hour from one address) is wrong on a
| developer's machine, where the same form is filled in a dozen times in an
| afternoon. See AppServiceProvider::registerSignupRateLimit.
*/
/*
| What a crawler is told (SS19).
|
| Generated rather than files in public/, because a static sitemap is a list
| somebody has to remember to update - and the first thing it does on a real
| install is go stale. robots.txt keeps the back office, the guest's table
| session and the signup form out of the index; the sitemap lists the
| marketing page, each live storefront and the blog. See SeoController.
*/
Route::get('robots.txt', [SeoController::class, 'robots'])->name('robots');
Route::get('sitemap.xml', [SeoController::class, 'sitemap'])->name('sitemap');

Route::middleware('guest')->group(function () {
    /*
    | The plan is part of the path, not a query string.
    |
    | `/signup/restaurant` is what a person can read out over a phone, what a
    | search engine treats as one page rather than as the same page tagged
    | three ways, and what stays intact when somebody pastes it into WhatsApp
    | and their client drops everything after the "?".
    |
    | Optional, because `/signup` on its own is a real request - somebody who
    | came from the header rather than from a price card - and it opens the
    | form on the cheapest plan. An unknown slug is not a 404: it falls back
    | to that same form. See SignupController::create.
    */
    Route::get('signup/{plan?}', [SignupController::class, 'create'])
        ->where('plan', '[a-z0-9]+(?:-[a-z0-9]+)*')
        ->name('signup');

    /*
    | Step two: the details, once a plan has been picked.
    |
    | Its own address rather than a panel that unhides, so the two steps are
    | two pages a browser understands: Back goes back a step, a half-finished
    | signup can be returned to, and none of it needs JavaScript.
    |
    | The plan is in the path here too, which is what lets this page state
    | what is being bought without keeping it in a session somebody's second
    | tab would overwrite.
    */
    Route::get('signup/{plan}/details', [SignupController::class, 'details'])
        ->where('plan', '[a-z0-9]+(?:-[a-z0-9]+)*')
        ->name('signup.details');

    Route::post('signup', [SignupController::class, 'store'])
        ->middleware('throttle:signup')
        ->name('signup.store');
});
/*
|--------------------------------------------------------------------------
| Table QR landing (public)
|--------------------------------------------------------------------------
|
| What a phone opens when it scans the sticker on a table (§3.3 - §3.7).
|
| Unauthenticated and outside every tenant scope, because there is nobody
| signed in when a guest scans - the token identifies the branch by itself.
|
| Short path on purpose. The URL is encoded into a QR that gets printed at
| 25mm on a sticker; every character is another module in the grid, and a
| denser grid is what actually fails on a cheap camera in a dim dining room.
|
| Throttled, because it is reachable by anyone with a camera and it writes:
| a scan opens a session. Generous enough that a table of six all scanning at
| once is fine, tight enough that a script cannot open ten thousand sittings.
*/
Route::get('t/{token}', [TableScanController::class, 'scan'])
    ->where('token', '[A-Za-z0-9]{32}')
    ->middleware('throttle:30,1')
    ->name('table.scan');

/*
| The page the guest sits on. Takes no parameter: the session lives in their
| own cookie, so a screenshot of this URL in a group chat is worth nothing.
*/
Route::get('t', [TableScanController::class, 'show'])->name('table.show');

/*
| The cart and the order (§3.5 - §3.10).
|
| Every one of these resolves the sitting from the guest's own cookie. None
| takes a table or session id from the request: a field a phone can edit would
| let anybody in the room order onto anybody else's bill.
|
| Throttled together with the scan, and more tightly on the write that creates
| work in a kitchen.
*/
Route::get('t/cart', [TableOrderController::class, 'cart'])->name('table.cart');
Route::get('t/orders', [TableOrderController::class, 'orders'])->name('table.orders');

Route::middleware('throttle:60,1')->group(function () {
    Route::post('t/cart', [TableOrderController::class, 'add'])->name('table.cart.add');
    Route::put('t/cart/{item}', [TableOrderController::class, 'update'])
        ->whereNumber('item')
        ->name('table.cart.update');
    Route::delete('t/cart/{item}', [TableOrderController::class, 'remove'])
        ->whereNumber('item')
        ->name('table.cart.remove');
});

/*
| Sending the cart. Tighter, because each one puts a ticket on a kitchen
| screen and a stuck finger should not put twenty there.
*/
Route::post('t/order', [TableOrderController::class, 'place'])
    ->middleware('throttle:10,1')
    ->name('table.order.place');

/*
| The optional OTP step (§3.8).
|
| `send` is the tightest throttle on the guest journey, and it is the only one
| here where the cost of abuse lands on somebody else: every request is a text
| message the restaurant pays for, aimed at a number the sender chooses. Five
| a minute is generous for a person and useless for anybody else.
|
| OtpService throttles again per number, from the database rather than a cache,
| so a resend limit survives a deploy - see its comments.
|
| `confirm` is looser: a guest fumbling a six-digit code on a phone in a noisy
| restaurant is the normal case, and the attempt limit on the code itself is
| what actually stops guessing.
*/
Route::post('t/verify/send', [TableVerifyController::class, 'send'])
    ->middleware('throttle:5,1')
    ->name('table.verify.send');

Route::post('t/verify', [TableVerifyController::class, 'confirm'])
    ->middleware('throttle:20,1')
    ->name('table.verify.confirm');

Route::get('t/verify', [TableVerifyController::class, 'show'])->name('table.verify');

/*
| Paying from the table (§11).
|
| Throttled harder than the cart: opening a provider order costs an API call
| and leaves a row in their dashboard, and a stuck finger should not leave
| forty. The confirm is looser because a guest whose network dropped mid-
| payment will legitimately retry it.
|
| Neither takes a session id from the request - the sitting comes from the
| guest's own cookie, the same rule the rest of this flow follows.
*/
Route::middleware('throttle:10,1')->group(function () {
    Route::post('t/pay', [TablePaymentController::class, 'start'])->name('table.pay.start');
});

Route::post('t/pay/confirm', [TablePaymentController::class, 'confirm'])
    ->middleware('throttle:30,1')
    ->name('table.pay.confirm');

/*
| The provider's webhook (§11).
|
| Outside every group on purpose: no session, no CSRF, no shop context and no
| authentication. The only thing between this URL and the internet is the
| signature, which the gateway checks before it reads anything else.
|
| Throttled generously rather than not at all - a provider retrying a backlog
| is normal, and a provider hammering us is not something to find out about
| by falling over.
*/
Route::post('payments/webhook', PaymentWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('payments.webhook');

/*
| The same URL in a browser.
|
| "Is this URL live" is the first thing anybody does after copying it into a
| provider's dashboard, and a 405 exception page is a frightening answer to a
| reasonable question. It says in words that the route exists and that only a
| signed POST does anything - and nothing else, because there is nothing here
| worth telling a stranger.
*/
Route::get('payments/webhook', [PaymentWebhookController::class, 'show'])
    ->middleware('throttle:30,1')
    ->name('payments.webhook.check');

/*
| How it was (§15).
|
| Reachable after the bill on purpose - most people rate a meal on the
| pavement outside, not at the table. The sitting comes from the guest's own
| cookie, and a closed sitting is still theirs.
|
| Throttled modestly: one guest changing their mind twice is normal, and the
| unique index on the sitting means volume buys an attacker nothing anyway.
*/
/*
| Installing the menu (§14).
|
| Generated rather than a static file, because the manifest is where branding
| has to be literal - the name on the home screen and the colour behind the
| splash both come from it. See TableManifestController.
*/
Route::get('t/manifest.json', TableManifestController::class)->name('table.manifest');

Route::get('t/feedback', [TableFeedbackController::class, 'show'])->name('table.feedback');

Route::post('t/feedback', [TableFeedbackController::class, 'store'])
    ->middleware('throttle:20,1')
    ->name('table.feedback.store');

Route::get('t/code-replaced', [TableScanController::class, 'retired'])->name('table.retired');
Route::get('t/not-serving', [TableScanController::class, 'closed'])->name('table.closed');
Route::get('t/session-ended', [TableScanController::class, 'expired'])->name('table.expired');

Route::prefix('admin')->name('admin.')->group(function () {

    // ip.allowed sits outside the controller so a globally blocked address
    // cannot use the form to probe which emails exist.
    Route::middleware(['guest', 'ip.allowed'])->group(function () {
        Route::get('login', [LoginController::class, 'create'])->name('login');
        Route::post('login', [LoginController::class, 'store'])->name('login.store');

        /*
        | "I have forgotten my password" (SS21).
        |
        | Needed the day restaurants started opening their own accounts: an
        | owner who chose their own password has no administrator to ask for
        | a fresh welcome link. The link it sends points at the set-password
        | screen below - one page that sets a password, two ways to reach it.
        |
        | Throttled hard, and on the POST only. Each one sends an email to an
        | address the sender chooses, which is somebody else's inbox and the
        | platform's sending reputation. Three an hour is more than anybody
        | who has genuinely forgotten a password needs.
        */
        Route::get('password/forgot', [PasswordResetController::class, 'create'])
            ->name('password.request');

        Route::post('password/forgot', [PasswordResetController::class, 'store'])
            ->middleware('throttle:3,60')
            ->name('password.email');

        /*
        | The "set your password" link from the welcome email.
        |
        | Throttled: the token is the only secret, so the submit endpoint is
        | the one place someone could grind at it.
        */
        Route::get('password/set/{token}', [PasswordSetupController::class, 'edit'])
            ->name('password.set');
        Route::post('password/set', [PasswordSetupController::class, 'update'])
            ->middleware('throttle:10,1')
            ->name('password.set.store');
    });

    /*
    |--------------------------------------------------------------------------
    | Billing: a restaurant paying for its own account (§11, §21)
    |--------------------------------------------------------------------------
    |
    | Its own group because of the one gate it must NOT have.
    |
    | `shop.subscribed` is deliberately absent: an outlet whose term has run
    | out is precisely the outlet that needs this page, and a checkout you
    | have to be paid up to reach is a support ticket rather than a payment.
    | EnsureShopIsSubscribed sends people here for that reason.
    |
    | Everything else still applies. `auth` and `admin` because this is the
    | back office, `tenant.active` because a suspended business is an account
    | matter that a card payment does not settle, and
    | settings.subscriptions.view because reading what your company is on is
    | the owner's right and the cashier's business.
    |
    | Nothing here writes a date. The provider's callback does, through
    | PaymentIntentService - see App\Http\Controllers\Admin\BillingController.
    */
    Route::middleware(['auth', 'admin', 'tenant.active', 'permission:settings.subscriptions.view'])
        ->group(function () {
            Route::get('billing', [BillingController::class, 'show'])->name('billing.show');

            Route::post('billing/checkout', [BillingController::class, 'checkout'])
                ->middleware('throttle:20,1')
                ->name('billing.checkout');

            Route::post('billing/confirm', [BillingController::class, 'confirm'])
                ->middleware('throttle:30,1')
                ->name('billing.confirm');
        });

    /*
    | The authenticated admin app.
    |
    | Four gates, in this order, and each answers a different question:
    |
    |   auth           is anybody signed in?
    |   admin          is this a back-office account at all?
    |   tenant.active  is their company still trading? A suspended tenant's
    |                  staff are signed out here rather than allowed to keep
    |                  working while the account is in dispute.
    |   shop.subscribed has THIS outlet been paid for? Subscriptions are sold
    |                  per outlet, so a lapsed branch is blocked while the
    |                  group's others keep trading - and nobody is signed out.
    |   module         does this branch run the line of business this route
    |                  belongs to? Derived from the route's own `permission:`
    |                  middleware, so it cannot drift from it.
    |
    | The permission itself is checked per route, below.
    */
    Route::middleware(['auth', 'admin', 'tenant.active', 'shop.subscribed', 'module'])->group(function () {
        Route::get('/', fn () => redirect()->route('admin.dashboard'));
        Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

        Route::get('dashboard', DashboardController::class)
            ->middleware('permission:dashboard.overview.view')
            ->name('dashboard');

        /*
        | Own-account screen. Ungated on purpose - it only ever reads and
        | writes $request->user(), so every admin may reach their own.
        */
        Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::put('profile/password', [ProfileController::class, 'updatePassword'])
            ->name('profile.password');
        Route::post('profile/avatar', [ProfileController::class, 'updateAvatar'])
            ->name('profile.avatar');
        Route::delete('profile/avatar', [ProfileController::class, 'destroyAvatar'])
            ->name('profile.avatar.destroy');

        /*
        | Every route below is gated on a permission from
        | config/permissions.php. Route-level guards are the outer defence;
        | the views additionally hide controls the user cannot use, and the
        | controllers re-check anything destructive.
        */

        /*
        | Shops (tenants).
        |
        | The switcher is the one endpoint here with no permission guard:
        | every admin may change which of *their own* shops they are working
        | in, and CurrentShop::set() refuses anything they have no pivot row
        | for. Authorisation is the data, not a permission name.
        */
        Route::post('shops/switch', ShopSwitchController::class)->name('shops.switch');

        /*
        | Companies (tenants) - the tier above shops.
        |
        | Same reasoning for the switcher: unguarded by middleware, because
        | CurrentTenant::set() refuses anything outside accessible(), and for
        | everyone except a Super Admin that list holds exactly one row.
        */
        Route::post('tenants/switch', TenantSwitchController::class)->name('tenants.switch');

        /*
        |----------------------------------------------------------------------
        | Notifications (SRS 15)
        |----------------------------------------------------------------------
        |
        | Ungated, like the profile screens above, and for a stricter reason:
        | the gating is per row. Each alert carries the permission that entitles
        | somebody to see it, so a user with no rights gets an empty bell rather
        | than a 403 on the header of every page in the system.
        */
        Route::get('alerts/bell', [AlertController::class, 'bell'])->name('alerts.bell');
        Route::get('alerts', [AlertController::class, 'index'])->name('alerts.index');
        Route::put('alerts/read-all', [AlertController::class, 'readAll'])->name('alerts.read-all');
        Route::put('alerts/{alert}/read', [AlertController::class, 'read'])
            ->whereNumber('alert')
            ->name('alerts.read');

        /*
        |======================================================================
        | The room: dining areas, tables and their QR codes (§4, §7)
        |======================================================================
        |
        | Module-gated as `dining` through the permission prefix, so a branch
        | that takes no dine-in orders has none of this in its sidebar or at
        | its URLs. See config/modules.php.
        */

        /*
        | Dining areas. Plain CRUD - the interesting behaviour is all on the
        | tables that hang off them.
        */
        Route::middleware('permission:dining.floors.view')->group(function () {
            Route::get('floors', [FloorController::class, 'index'])->name('floors.index');
        });

        Route::middleware('permission:dining.floors.create')->group(function () {
            Route::get('floors/create', [FloorController::class, 'create'])->name('floors.create');
            Route::post('floors', [FloorController::class, 'store'])->name('floors.store');
        });

        Route::middleware('permission:dining.floors.edit')->group(function () {
            Route::get('floors/{floor}/edit', [FloorController::class, 'edit'])
                ->whereNumber('floor')
                ->name('floors.edit');
            Route::put('floors/{floor}', [FloorController::class, 'update'])
                ->whereNumber('floor')
                ->name('floors.update');
            Route::put('floors/{floor}/status', [FloorController::class, 'toggleStatus'])
                ->whereNumber('floor')
                ->name('floors.status');
        });

        Route::delete('floors/{floor}', [FloorController::class, 'destroy'])
            ->whereNumber('floor')
            ->middleware('permission:dining.floors.delete')
            ->name('floors.destroy');

        /*
        | Tables.
        |
        | `plan` is declared before `{table}` so "plan" is never swallowed as
        | a table id, and every id route says whereNumber for the same reason.
        */
        Route::middleware('permission:dining.tables.view')->group(function () {
            Route::get('tables', [RestaurantTableController::class, 'index'])->name('tables.index');
            Route::get('tables/plan', [RestaurantTableController::class, 'plan'])->name('tables.plan');
            Route::get('tables/{table}', [RestaurantTableController::class, 'show'])
                ->whereNumber('table')
                ->name('tables.show');
        });

        Route::middleware('permission:dining.tables.create')->group(function () {
            Route::get('tables/create', [RestaurantTableController::class, 'create'])->name('tables.create');
            Route::post('tables', [RestaurantTableController::class, 'store'])->name('tables.store');
        });

        Route::middleware('permission:dining.tables.edit')->group(function () {
            Route::get('tables/{table}/edit', [RestaurantTableController::class, 'edit'])
                ->whereNumber('table')
                ->name('tables.edit');
            Route::put('tables/{table}', [RestaurantTableController::class, 'update'])
                ->whereNumber('table')
                ->name('tables.update');
            Route::put('tables/{table}/service', [RestaurantTableController::class, 'toggleStatus'])
                ->whereNumber('table')
                ->name('tables.service');
        });

        /*
        | Seating, clearing and holding a table, and moving it on the plan.
        |
        | `adjust` rather than `edit`, because this is what the front of house
        | does all evening and renumbering the restaurant is not. A captain
        | holds this and not the one above it.
        */
        Route::middleware('permission:dining.tables.adjust')->group(function () {
            Route::put('tables/{table}/status', [RestaurantTableController::class, 'setStatus'])
                ->whereNumber('table')
                ->name('tables.status');
            Route::put('tables/{table}/position', [RestaurantTableController::class, 'setPosition'])
                ->whereNumber('table')
                ->name('tables.position');
        });

        Route::delete('tables/{table}', [RestaurantTableController::class, 'destroy'])
            ->whereNumber('table')
            ->middleware('permission:dining.tables.delete')
            ->name('tables.destroy');

        /*
        | QR codes.
        |
        | Issuing and regenerating share `dining.qr.create`: both put a new
        | code on a table, and the only difference is whether one was there
        | before. Withdrawing is `delete` because from the table's point of
        | view that is what it is.
        */
        Route::middleware('permission:dining.qr.view')->group(function () {
            Route::get('qr', [TableQrController::class, 'index'])->name('qr.index');
            Route::get('qr/{table}', [TableQrController::class, 'show'])
                ->whereNumber('table')
                ->name('qr.show');
        });

        Route::get('qr/sheet/print', [TableQrController::class, 'sheet'])
            ->middleware('permission:dining.qr.print')
            ->name('qr.sheet');

        Route::middleware('permission:dining.qr.create')->group(function () {
            Route::post('qr/issue-missing', [TableQrController::class, 'issueMissing'])
                ->name('qr.issue-missing');
            Route::post('qr/{table}/regenerate', [TableQrController::class, 'regenerate'])
                ->whereNumber('table')
                ->name('qr.regenerate');
        });

        Route::delete('qr/{table}', [TableQrController::class, 'revoke'])
            ->whereNumber('table')
            ->middleware('permission:dining.qr.delete')
            ->name('qr.revoke');

        /*
        | Reservations (§7, §21).
        |
        | Module-gated as `dining` through the permission prefix: a shop with
        | no floor plan has no table to promise anybody.
        |
        | `adjust` rather than `edit` on seating, no-shows and closing. That
        | split is the whole reason the permission has six actions: a host at
        | the door must be able to seat a party without also being able to
        | rewrite the evening's book.
        */
        Route::middleware('permission:dining.reservations.view')->group(function () {
            Route::get('reservations', [ReservationController::class, 'index'])->name('reservations.index');
            Route::get('reservations/{reservation}', [ReservationController::class, 'show'])
                ->whereNumber('reservation')
                ->name('reservations.show');
        });

        Route::middleware('permission:dining.reservations.create')->group(function () {
            // Before the wildcard, so "create" is not read as an id.
            Route::get('reservations/create', [ReservationController::class, 'create'])->name('reservations.create');
            Route::post('reservations', [ReservationController::class, 'store'])->name('reservations.store');
        });

        Route::middleware('permission:dining.reservations.edit')->group(function () {
            Route::get('reservations/{reservation}/edit', [ReservationController::class, 'edit'])
                ->whereNumber('reservation')
                ->name('reservations.edit');
            Route::put('reservations/{reservation}', [ReservationController::class, 'update'])
                ->whereNumber('reservation')
                ->name('reservations.update');
        });

        Route::middleware('permission:dining.reservations.adjust')->group(function () {
            Route::put('reservations/{reservation}/confirm', [ReservationController::class, 'confirm'])
                ->whereNumber('reservation')
                ->name('reservations.confirm');
            Route::put('reservations/{reservation}/seat', [ReservationController::class, 'seat'])
                ->whereNumber('reservation')
                ->name('reservations.seat');
            Route::put('reservations/{reservation}/complete', [ReservationController::class, 'complete'])
                ->whereNumber('reservation')
                ->name('reservations.complete');
            Route::put('reservations/{reservation}/no-show', [ReservationController::class, 'noShow'])
                ->whereNumber('reservation')
                ->name('reservations.no-show');
            Route::put('reservations/{reservation}/cancel', [ReservationController::class, 'cancel'])
                ->whereNumber('reservation')
                ->name('reservations.cancel');
        });

        Route::delete('reservations/{reservation}', [ReservationController::class, 'destroy'])
            ->whereNumber('reservation')
            ->middleware('permission:dining.reservations.delete')
            ->name('reservations.destroy');

        /*
        |======================================================================
        | The kitchen: the display and the stations behind it (§9)
        |======================================================================
        |
        | Module-gated as `kitchen` through the permission prefix - separate
        | from `dining`, because a cloud kitchen with no dining room still
        | cooks. See config/modules.php.
        */

        /*
        | The board.
        |
        | `index` serves both the screen and the poll: with an X-Fragment
        | header it returns the board on its own, which is what the wall
        | screen re-fetches every few seconds. One route, so the two can
        | never drift apart.
        */
        Route::middleware('permission:kitchen.tickets.view')->group(function () {
            Route::get('kitchen', [KitchenController::class, 'index'])->name('kitchen.index');
            Route::get('kitchen/tickets/{order}/recall', [KitchenController::class, 'recallForm'])
                ->whereNumber('order')
                ->name('kitchen.recall-form');
        });

        Route::get('kitchen/tickets/{order}/kot', [KitchenController::class, 'kot'])
            ->whereNumber('order')
            ->middleware('permission:kitchen.tickets.print')
            ->name('kitchen.kot');

        /*
        | Straight at the printer beside the station - no dialog, nobody at a
        | screen. Same right as the print page: whoever may print a ticket may
        | print it on paper.
        */
        Route::post('kitchen/tickets/{order}/kot/send', [KitchenController::class, 'sendKot'])
            ->whereNumber('order')
            ->middleware('permission:kitchen.tickets.print')
            ->name('kitchen.kot.send');

        /*
        | Bumping. PUT rather than POST: moving a ticket to Ready twice leaves
        | it Ready, and a cook on a hot line double-taps.
        */
        Route::middleware('permission:kitchen.tickets.advance')->group(function () {
            Route::put('kitchen/tickets/{order}/bump', [KitchenController::class, 'bumpTicket'])
                ->whereNumber('order')
                ->name('kitchen.bump');
            Route::put('kitchen/lines/{item}/bump', [KitchenController::class, 'bumpLine'])
                ->whereNumber('item')
                ->name('kitchen.bump-line');
        });

        /*
        | Going back down the ladder. Its own right, because it rewrites the
        | timestamps the preparation-time report is built from - §9's "reopen
        | only for authorized staff".
        */
        Route::put('kitchen/tickets/{order}/recall', [KitchenController::class, 'recall'])
            ->whereNumber('order')
            ->middleware('permission:kitchen.tickets.recall')
            ->name('kitchen.recall');

        /*
        | Stations. Plain CRUD, set up once when the outlet is configured.
        */
        Route::middleware('permission:kitchen.stations.view')->group(function () {
            Route::get('kitchen-stations', [KitchenStationController::class, 'index'])
                ->name('kitchen-stations.index');
        });

        Route::middleware('permission:kitchen.stations.create')->group(function () {
            Route::get('kitchen-stations/create', [KitchenStationController::class, 'create'])
                ->name('kitchen-stations.create');
            Route::post('kitchen-stations', [KitchenStationController::class, 'store'])
                ->name('kitchen-stations.store');
        });

        Route::middleware('permission:kitchen.stations.edit')->group(function () {
            Route::get('kitchen-stations/{station}/edit', [KitchenStationController::class, 'edit'])
                ->whereNumber('station')
                ->name('kitchen-stations.edit');
            Route::put('kitchen-stations/{station}', [KitchenStationController::class, 'update'])
                ->whereNumber('station')
                ->name('kitchen-stations.update');
            Route::put('kitchen-stations/{station}/status', [KitchenStationController::class, 'toggleStatus'])
                ->whereNumber('station')
                ->name('kitchen-stations.status');
            Route::put('kitchen-stations/{station}/default', [KitchenStationController::class, 'makeDefault'])
                ->whereNumber('station')
                ->name('kitchen-stations.default');
        });

        Route::delete('kitchen-stations/{station}', [KitchenStationController::class, 'destroy'])
            ->whereNumber('station')
            ->middleware('permission:kitchen.stations.delete')
            ->name('kitchen-stations.destroy');

        /*
        |======================================================================
        | Recipes: what a dish is made of (§10)
        |======================================================================
        |
        | Gated by the `inventory` module through the permission prefix - a
        | branch that keeps no stock has nothing to deduct from.
        |
        | `{product}` is the dish. Its ingredients are products too, so the
        | route reads a little oddly until you remember that an ingredient is
        | a product with `is_ingredient` set - see the migration.
        */
        Route::middleware('permission:inventory.recipes.view')->group(function () {
            Route::get('recipes', [RecipeController::class, 'index'])->name('recipes.index');
            Route::get('recipes/{product}', [RecipeController::class, 'show'])
                ->whereNumber('product')
                ->name('recipes.show');
        });

        Route::get('recipes/export/csv', [RecipeController::class, 'export'])
            ->middleware('permission:inventory.recipes.export')
            ->name('recipes.export');

        Route::middleware('permission:inventory.recipes.edit')->group(function () {
            Route::get('recipes/{product}/edit', [RecipeController::class, 'edit'])
                ->whereNumber('product')
                ->name('recipes.edit');
            Route::put('recipes/{product}', [RecipeController::class, 'update'])
                ->whereNumber('product')
                ->name('recipes.update');
        });

        /*
        |======================================================================
        | Wastage: what the kitchen threw away (§10)
        |======================================================================
        |
        | A log, not a document - one row and one stock movement, immediately.
        | See the migration for why it is not a stock adjustment.
        */
        Route::middleware('permission:inventory.wastage.view')->group(function () {
            Route::get('wastage', [WastageController::class, 'index'])->name('wastage.index');
        });

        /*
        |======================================================================
        | Live orders (§4, §5)
        |======================================================================
        |
        | The manager's screen, not the cook's - see LiveOrderController. It
        | reads `sales.orders.view` rather than a right of its own, because it
        | is the same orders the history screen shows, filtered to the ones
        | somebody is still waiting for. A second permission over the same rows
        | would only be a second thing to forget to grant.
        |
        | `index` serves both the screen and the poll: with an X-Fragment
        | header it returns the list alone.
        */
        Route::get('live-orders', [LiveOrderController::class, 'index'])
            ->middleware('permission:sales.orders.view')
            ->name('live-orders.index');

        Route::get('wastage/export/csv', [WastageController::class, 'export'])
            ->middleware('permission:inventory.wastage.export')
            ->name('wastage.export');

        Route::middleware('permission:inventory.wastage.create')->group(function () {
            Route::get('wastage/create', [WastageController::class, 'create'])->name('wastage.create');
            Route::post('wastage', [WastageController::class, 'store'])->name('wastage.store');
        });

        /*
        | Reversing puts stock back, so it is its own right: being able to
        | un-record a loss is being able to hide one.
        */
        Route::delete('wastage/{wastage}', [WastageController::class, 'destroy'])
            ->whereNumber('wastage')
            ->middleware('permission:inventory.wastage.delete')
            ->name('wastage.destroy');

        /*
        |======================================================================
        | Settling a table (§6)
        |======================================================================
        |
        | The counter's other half. Gated by the `dining` module through the
        | `pos.tables` prefix, because billing a table needs tables - see
        | config/modules.php.
        |
        | Every id here is a table *session*, not a table. A table is a piece
        | of furniture that has seated a hundred parties; the sitting is the
        | thing that owes money, and routing by table would make "bill table
        | four" ambiguous the moment two parties have sat at it in one evening.
        */
        Route::middleware('permission:pos.tables.view')->group(function () {
            Route::get('table-bills', [TableBillController::class, 'index'])
                ->name('table-bills.index');
            Route::get('table-bills/{session}', [TableBillController::class, 'show'])
                ->whereNumber('session')
                ->name('table-bills.show');
            Route::get('table-bills/{session}/split', [TableBillController::class, 'splitForm'])
                ->whereNumber('session')
                ->name('table-bills.split-form');
        });

        /*
        | Raising the bill - in full or as one part of a split. One endpoint
        | for both; see the controller.
        */
        Route::post('table-bills/{session}/settle', [TableBillController::class, 'settle'])
            ->whereNumber('session')
            ->middleware('permission:pos.tables.settle')
            ->name('table-bills.settle');

        /*
        | The code a guest scans for their share of it.
        |
        | GET, and it writes nothing: the platform is not in the payment path,
        | so this renders a `upi://pay` URI and hands it back - see
        | App\Support\UpiQr. Under `settle` rather than `view` because a code
        | for a named amount is part of taking the money, and separate from the
        | bill fragment because the amount is not known until somebody has said
        | how the table is splitting it.
        */
        Route::get('table-bills/{session}/upi-qr', [TableBillController::class, 'upiQr'])
            ->whereNumber('session')
            ->middleware('permission:pos.tables.settle')
            ->name('table-bills.upi-qr');

        /*
        | Taking an order at the table.
        |
        | The same two steps the guest's phone takes - add to the sitting's
        | cart, then send the lot to the kitchen - because they are the same
        | cart and the same rules. A captain with a pad and a guest with a
        | phone must not be able to put different things on one table.
        */
        Route::middleware('permission:pos.tables.order')->group(function () {
            Route::get('table-bills/{session}/order', [TableBillController::class, 'orderForm'])
                ->whereNumber('session')
                ->name('table-bills.order-form');
            Route::post('table-bills/{session}/cart', [TableBillController::class, 'addToCart'])
                ->whereNumber('session')
                ->name('table-bills.cart');
            Route::delete('table-bills/{session}/cart/{item}', [TableBillController::class, 'removeFromCart'])
                ->whereNumber('session')
                ->whereNumber('item')
                ->name('table-bills.cart-remove');
            Route::post('table-bills/{session}/send', [TableBillController::class, 'send'])
                ->whereNumber('session')
                ->name('table-bills.send');
        });

        /*
        | Moving food between tables. `move` covers both a single ticket and a
        | whole table folded into another, because they are the same act at two
        | sizes and nobody should hold one without the other.
        */
        Route::middleware('permission:pos.tables.move')->group(function () {
            Route::get('table-bills/{session}/merge', [TableBillController::class, 'mergeForm'])
                ->whereNumber('session')
                ->name('table-bills.merge-form');
            Route::put('table-bills/{session}/merge', [TableBillController::class, 'merge'])
                ->whereNumber('session')
                ->name('table-bills.merge');
            Route::put('table-bills/{session}/move', [TableBillController::class, 'move'])
                ->whereNumber('session')
                ->name('table-bills.move');
        });

        /*
        | Clearing a table that left without paying. Its own right, because it
        | is the only action here that ends a sitting with money owed and no
        | document raised.
        */
        Route::middleware('permission:pos.tables.write_off')->group(function () {
            Route::get('table-bills/{session}/write-off', [TableBillController::class, 'writeOffForm'])
                ->whereNumber('session')
                ->name('table-bills.write-off-form');
            Route::put('table-bills/{session}/write-off', [TableBillController::class, 'writeOff'])
                ->whereNumber('session')
                ->name('table-bills.write-off');
        });

        /*
        | The company master (SRS 10.1).
        |
        | The permission opens the screen; TenantController decides *whose*
        | companies are on it. A tenant owner holding settings.tenants.view
        | sees one row - their own - and creating or removing a business is
        | refused there as a platform action.
        */
        Route::middleware('permission:settings.tenants.view')->group(function () {
            Route::get('tenants', [TenantController::class, 'index'])->name('tenants.index');
            Route::get('tenants/{tenant}', [TenantController::class, 'show'])
                ->whereNumber('tenant')
                ->name('tenants.show');
        });

        Route::middleware('permission:settings.tenants.create')->group(function () {
            // Registered before the wildcard so "create" is not read as an id.
            Route::get('tenants/create', [TenantController::class, 'create'])->name('tenants.create');
            Route::post('tenants', [TenantController::class, 'store'])->name('tenants.store');
        });

        Route::middleware('permission:settings.tenants.edit')->group(function () {
            Route::get('tenants/{tenant}/edit', [TenantController::class, 'edit'])
                ->whereNumber('tenant')
                ->name('tenants.edit');
            Route::put('tenants/{tenant}', [TenantController::class, 'update'])
                ->whereNumber('tenant')
                ->name('tenants.update');
            Route::put('tenants/{tenant}/status', [TenantController::class, 'toggleStatus'])
                ->whereNumber('tenant')
                ->name('tenants.status');
            Route::delete('tenants/{tenant}/logo', [TenantController::class, 'destroyLogo'])
                ->whereNumber('tenant')
                ->name('tenants.logo.destroy');
        });

        Route::delete('tenants/{tenant}', [TenantController::class, 'destroy'])
            ->whereNumber('tenant')
            ->middleware('permission:settings.tenants.delete')
            ->name('tenants.destroy');

        /*
        | What the platform sells (§21).
        |
        | Super Admin's catalogue. Nothing here is shop-scoped or
        | tenant-scoped, because a price list belongs to the platform rather
        | than to any business on it.
        */
        Route::middleware('permission:settings.plans.view')->group(function () {
            Route::get('plans', [PlanController::class, 'index'])->name('plans.index');
            Route::get('plans/{plan}', [PlanController::class, 'show'])
                ->whereNumber('plan')
                ->name('plans.show');
        });

        Route::middleware('permission:settings.plans.create')->group(function () {
            // Before the wildcard, so "create" is not read as an id.
            Route::get('plans/create', [PlanController::class, 'create'])->name('plans.create');
            Route::post('plans', [PlanController::class, 'store'])->name('plans.store');
        });

        Route::middleware('permission:settings.plans.edit')->group(function () {
            Route::get('plans/{plan}/edit', [PlanController::class, 'edit'])
                ->whereNumber('plan')
                ->name('plans.edit');
            Route::put('plans/{plan}', [PlanController::class, 'update'])
                ->whereNumber('plan')
                ->name('plans.update');
            Route::put('plans/{plan}/status', [PlanController::class, 'toggleStatus'])
                ->whereNumber('plan')
                ->name('plans.status');
        });

        Route::delete('plans/{plan}', [PlanController::class, 'destroy'])
            ->whereNumber('plan')
            ->middleware('permission:settings.plans.delete')
            ->name('plans.destroy');

        /*
        | Subscriptions and billing (§4, §21).
        |
        | One permission opens the screens; the controller decides whose
        | businesses are on them. A Tenant Owner holding
        | settings.subscriptions.view sees their own company and nobody
        | else's - and every write below is gated on `.edit`, which they do
        | not hold, because renewing your own subscription is not a feature.
        |
        | `subscription` (singular) is the restaurant's own screen, and is
        | registered before the wildcard so it is not read as a tenant id.
        */
        Route::middleware('permission:settings.subscriptions.view')->group(function () {
            Route::get('subscription', [SubscriptionController::class, 'mine'])->name('subscription.mine');

            Route::get('subscriptions', [SubscriptionController::class, 'index'])->name('subscriptions.index');
            Route::get('subscriptions/{tenant}', [SubscriptionController::class, 'show'])
                ->whereNumber('tenant')
                ->name('subscriptions.show');
            Route::get('subscriptions/{tenant}/assign', [SubscriptionController::class, 'assign'])
                ->whereNumber('tenant')
                ->name('subscriptions.assign');
            Route::get('subscriptions/{tenant}/payment', [SubscriptionController::class, 'paymentForm'])
                ->whereNumber('tenant')
                ->name('subscriptions.payment');
        });

        Route::middleware('permission:settings.subscriptions.edit')->group(function () {
            Route::post('subscriptions/{tenant}', [SubscriptionController::class, 'store'])
                ->whereNumber('tenant')
                ->name('subscriptions.store');
            Route::post('subscriptions/{tenant}/payment', [SubscriptionController::class, 'recordPayment'])
                ->whereNumber('tenant')
                ->name('subscriptions.payment.store');
            Route::put('subscriptions/{tenant}/cancel', [SubscriptionController::class, 'cancel'])
                ->whereNumber('tenant')
                ->name('subscriptions.cancel');
            Route::put('subscriptions/{tenant}/resume', [SubscriptionController::class, 'resume'])
                ->whereNumber('tenant')
                ->name('subscriptions.resume');
        });

        /*
        | Printers and devices (§4, §6, §8, §21).
        |
        | Not module-gated: a printer is a fact about the premises, not a line
        | of business. A shop with no dining room still prints bills.
        |
        | The log is a separate screen rather than a tab, because it is read
        | for a different reason - "why did the tandoor never get that ticket"
        | - and usually by somebody who is not configuring anything.
        */
        /*
        | Loyalty and guest feedback (§15, §21).
        |
        | Module-gated as `customers` through the permission prefix: a counter
        | that keeps no customer records has nobody to give points to.
        |
        | `adjust` is separated from `edit` on purpose - it is the one action
        | that creates value out of nothing, and a till operator who could
        | grant themselves points is a fraud waiting to be found by an
        | accountant.
        */
        /*
        | Price lists and happy hours (§8, §16).
        |
        | Every write forgets the price memo - a memo that outlived an edit
        | would keep an offer running after somebody switched it off.
        */
        Route::middleware('permission:inventory.price_lists.view')->group(function () {
            Route::get('price-lists', [PriceListController::class, 'index'])->name('price-lists.index');
            // Before the wildcard, so "create" is not read as an id.
            Route::get('price-lists/create', [PriceListController::class, 'create'])
                ->middleware('permission:inventory.price_lists.create')
                ->name('price-lists.create');
            Route::get('price-lists/{priceList}/edit', [PriceListController::class, 'edit'])
                ->whereNumber('priceList')
                ->middleware('permission:inventory.price_lists.edit')
                ->name('price-lists.edit');
        });

        Route::post('price-lists', [PriceListController::class, 'store'])
            ->middleware('permission:inventory.price_lists.create')
            ->name('price-lists.store');

        Route::put('price-lists/{priceList}', [PriceListController::class, 'update'])
            ->whereNumber('priceList')
            ->middleware('permission:inventory.price_lists.edit')
            ->name('price-lists.update');

        Route::put('price-lists/{priceList}/toggle', [PriceListController::class, 'toggle'])
            ->whereNumber('priceList')
            ->middleware('permission:inventory.price_lists.edit')
            ->name('price-lists.toggle');

        Route::delete('price-lists/{priceList}', [PriceListController::class, 'destroy'])
            ->whereNumber('priceList')
            ->middleware('permission:inventory.price_lists.delete')
            ->name('price-lists.destroy');

        Route::middleware('permission:crm.loyalty.view')->group(function () {
            Route::get('loyalty', [LoyaltyController::class, 'index'])->name('loyalty.index');
            // Before the wildcard, so "settings" is not read as a customer id.
            Route::get('loyalty/settings', [LoyaltyController::class, 'settings'])->name('loyalty.settings');
            Route::get('loyalty/{customer}', [LoyaltyController::class, 'show'])
                ->whereNumber('customer')
                ->name('loyalty.show');
        });

        Route::put('loyalty/settings', [LoyaltyController::class, 'save'])
            ->middleware('permission:crm.loyalty.edit')
            ->name('loyalty.save');

        Route::post('loyalty/{customer}/adjust', [LoyaltyController::class, 'adjust'])
            ->whereNumber('customer')
            ->middleware('permission:crm.loyalty.adjust')
            ->name('loyalty.adjust');

        /*
        | Campaigns (§15, §21).
        |
        | `approve` is what sends, and it is deliberately not `edit`: writing
        | a draft costs nothing, and a message that has gone to four hundred
        | people cannot be recalled.
        */
        Route::middleware('permission:crm.campaigns.view')->group(function () {
            Route::get('campaigns', [CampaignController::class, 'index'])->name('campaigns.index');
            // Before the wildcard, so neither is read as an id.
            Route::get('campaigns/create', [CampaignController::class, 'create'])
                ->middleware('permission:crm.campaigns.create')
                ->name('campaigns.create');
            Route::post('campaigns/preview', [CampaignController::class, 'preview'])->name('campaigns.preview');
            Route::get('campaigns/{campaign}', [CampaignController::class, 'show'])
                ->whereNumber('campaign')
                ->name('campaigns.show');
            Route::get('campaigns/{campaign}/edit', [CampaignController::class, 'edit'])
                ->whereNumber('campaign')
                ->middleware('permission:crm.campaigns.edit')
                ->name('campaigns.edit');
        });

        Route::post('campaigns', [CampaignController::class, 'store'])
            ->middleware('permission:crm.campaigns.create')
            ->name('campaigns.store');

        Route::put('campaigns/{campaign}', [CampaignController::class, 'update'])
            ->whereNumber('campaign')
            ->middleware('permission:crm.campaigns.edit')
            ->name('campaigns.update');

        Route::post('campaigns/{campaign}/send', [CampaignController::class, 'send'])
            ->whereNumber('campaign')
            ->middleware('permission:crm.campaigns.approve')
            ->name('campaigns.send');

        Route::put('campaigns/{campaign}/cancel', [CampaignController::class, 'cancel'])
            ->whereNumber('campaign')
            ->middleware('permission:crm.campaigns.approve')
            ->name('campaigns.cancel');

        Route::delete('campaigns/{campaign}', [CampaignController::class, 'destroy'])
            ->whereNumber('campaign')
            ->middleware('permission:crm.campaigns.delete')
            ->name('campaigns.destroy');

        Route::middleware('permission:crm.feedback.view')->group(function () {
            Route::get('feedback', [FeedbackController::class, 'index'])->name('feedback.index');
            Route::get('feedback/{feedback}', [FeedbackController::class, 'show'])
                ->whereNumber('feedback')
                ->name('feedback.show');
        });

        Route::put('feedback/{feedback}', [FeedbackController::class, 'respond'])
            ->whereNumber('feedback')
            ->middleware('permission:crm.feedback.edit')
            ->name('feedback.respond');

        Route::delete('feedback/{feedback}', [FeedbackController::class, 'destroy'])
            ->whereNumber('feedback')
            ->middleware('permission:crm.feedback.delete')
            ->name('feedback.destroy');

        /*
        | Online payments, refunds and settlement (§4, §11).
        |
        | Separate from the customer-ledger payment screens: this is the
        | gateway's side - what guests paid from their phones, what went back,
        | and whether the provider's statement agrees.
        |
        | The refund is gated on its own right because it sends real money out
        | of the restaurant's account.
        */
        Route::middleware('permission:finance.online_payments.view')->group(function () {
            Route::get('online-payments', [OnlinePaymentController::class, 'index'])
                ->name('online-payments.index');
            Route::get('online-payments/refunds', [OnlinePaymentController::class, 'refunds'])
                ->name('online-payments.refunds');
            Route::get('online-payments/settlements', [OnlinePaymentController::class, 'settlements'])
                ->name('online-payments.settlements');
            Route::get('online-payments/{intent}/refund', [OnlinePaymentController::class, 'refundForm'])
                ->whereNumber('intent')
                ->name('online-payments.refund-form');
        });

        Route::post('online-payments/{intent}/refund', [OnlinePaymentController::class, 'refund'])
            ->whereNumber('intent')
            ->middleware('permission:finance.online_payments.refund')
            ->name('online-payments.refund');

        /*
        | API tokens (§2, §21).
        |
        | Scoped to the caller's own tokens throughout - see
        | ApiTokenController - so nobody can revoke a colleague's integration
        | by guessing an id.
        */
        Route::get('api-tokens', [ApiTokenController::class, 'index'])
            ->middleware('permission:settings.api_tokens.view')
            ->name('api-tokens.index');

        Route::post('api-tokens', [ApiTokenController::class, 'store'])
            ->middleware('permission:settings.api_tokens.create')
            ->name('api-tokens.store');

        Route::delete('api-tokens/{token}', [ApiTokenController::class, 'destroy'])
            ->whereNumber('token')
            ->middleware('permission:settings.api_tokens.delete')
            ->name('api-tokens.destroy');

        Route::middleware('permission:settings.printers.view')->group(function () {
            Route::get('printers', [PrinterController::class, 'index'])->name('printers.index');
            Route::get('printers/jobs', [PrinterController::class, 'jobs'])->name('printers.jobs');
        });

        Route::middleware('permission:settings.printers.create')->group(function () {
            // Registered before the wildcard so "create" is not read as an id.
            Route::get('printers/create', [PrinterController::class, 'create'])->name('printers.create');
            Route::post('printers', [PrinterController::class, 'store'])->name('printers.store');
        });

        Route::middleware('permission:settings.printers.edit')->group(function () {
            Route::get('printers/{printer}/edit', [PrinterController::class, 'edit'])
                ->whereNumber('printer')
                ->name('printers.edit');
            Route::put('printers/{printer}', [PrinterController::class, 'update'])
                ->whereNumber('printer')
                ->name('printers.update');
        });

        /*
        | The test page. `print` rather than `edit`: a cashier should be able
        | to check a printer is alive without also being able to re-address
        | the tandoor's printer at the far end of the building.
        */
        Route::post('printers/{printer}/test', [PrinterController::class, 'test'])
            ->whereNumber('printer')
            ->middleware('permission:settings.printers.print')
            ->name('printers.test');

        Route::delete('printers/{printer}', [PrinterController::class, 'destroy'])
            ->whereNumber('printer')
            ->middleware('permission:settings.printers.delete')
            ->name('printers.destroy');

        Route::middleware('permission:settings.shops.view')->group(function () {
            Route::get('shops', [ShopController::class, 'index'])->name('shops.index');
            Route::get('shops/{shop}', [ShopController::class, 'show'])
                ->whereNumber('shop')
                ->name('shops.show');
        });

        Route::middleware('permission:settings.shops.create')->group(function () {
            // Registered before the wildcard so "create" is not read as an id.
            Route::get('shops/create', [ShopController::class, 'create'])->name('shops.create');
            Route::post('shops', [ShopController::class, 'store'])->name('shops.store');
        });

        Route::middleware('permission:settings.shops.edit')->group(function () {
            Route::get('shops/{shop}/edit', [ShopController::class, 'edit'])->name('shops.edit');
            Route::put('shops/{shop}', [ShopController::class, 'update'])->name('shops.update');
            Route::put('shops/{shop}/status', [ShopController::class, 'toggleStatus'])
                ->name('shops.status');
            Route::delete('shops/{shop}/logo', [ShopController::class, 'destroyLogo'])
                ->name('shops.logo.destroy');
        });

        Route::delete('shops/{shop}', [ShopController::class, 'destroy'])
            ->middleware('permission:settings.shops.delete')
            ->name('shops.destroy');

        Route::get('settings/company', [CompanySettingController::class, 'edit'])
            ->middleware('permission:settings.general.view')
            ->name('settings.company');

        Route::middleware('permission:settings.general.edit')->group(function () {
            Route::put('settings/company/{section}', [CompanySettingController::class, 'update'])
                ->name('settings.company.update');
            Route::delete('settings/company/{section}/file/{key}', [CompanySettingController::class, 'destroyFile'])
                ->name('settings.company.file.destroy');
        });

        /*
        | Categories. Every screen answers a fragment when asked for one, so
        | the whole module runs inside modals and an AJAX list.
        */
        Route::middleware('permission:inventory.categories.view')->group(function () {
            Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
            Route::get('categories/{category}', [CategoryController::class, 'show'])
                ->whereNumber('category')
                ->name('categories.show');
        });

        Route::middleware('permission:inventory.categories.create')->group(function () {
            // Registered before the wildcard so "create" is not read as an id.
            Route::get('categories/create', [CategoryController::class, 'create'])->name('categories.create');
            Route::post('categories', [CategoryController::class, 'store'])->name('categories.store');
        });

        Route::middleware('permission:inventory.categories.edit')->group(function () {
            Route::get('categories/{category}/edit', [CategoryController::class, 'edit'])->name('categories.edit');
            Route::put('categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
            Route::put('categories/{category}/status', [CategoryController::class, 'toggleStatus'])
                ->name('categories.status');
            Route::delete('categories/{category}/image', [CategoryController::class, 'destroyImage'])
                ->name('categories.image.destroy');
        });

        Route::delete('categories/{category}', [CategoryController::class, 'destroy'])
            ->middleware('permission:inventory.categories.delete')
            ->name('categories.destroy');

        /*
        | Products. Global catalogue, but every price and stock figure the
        | screens show is read for the shop in context.
        */
        Route::get('products/export', [ProductController::class, 'export'])
            ->middleware('permission:inventory.products.export')
            ->name('products.export');

        /*
        | Bulk menu import (§8).
        |
        | `template` is a GET so it can be a plain link, and it is the *same*
        | row builder the export uses - a file that downloads with different
        | columns from the ones the import reads is the commonest way a bulk
        | import wastes an afternoon.
        */
        Route::middleware('permission:inventory.products.import')->group(function () {
            Route::get('products/import', [ProductController::class, 'importForm'])
                ->name('products.import-form');
            Route::get('products/import/template', [ProductController::class, 'template'])
                ->name('products.template');
            Route::post('products/import', [ProductController::class, 'import'])
                ->name('products.import');
        });

        Route::middleware('permission:inventory.products.view')->group(function () {
            Route::get('products', [ProductController::class, 'index'])->name('products.index');
            // Barcode/SKU/name lookup for the POS and the invoice forms.
            Route::get('products/lookup', [ProductController::class, 'lookup'])->name('products.lookup');
            Route::get('products/{product}', [ProductController::class, 'show'])
                ->whereNumber('product')
                ->name('products.show');
        });

        Route::middleware('permission:inventory.products.create')->group(function () {
            // Registered before the wildcard so "create" is not read as an id.
            Route::get('products/create', [ProductController::class, 'create'])->name('products.create');
            Route::post('products', [ProductController::class, 'store'])->name('products.store');
        });

        Route::middleware('permission:inventory.products.edit')->group(function () {
            Route::get('products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
            Route::put('products/{product}', [ProductController::class, 'update'])->name('products.update');
            /*
            | Sold out rides on `edit` rather than having a right of its own.
            | Whoever may change a dish may take it off the menu for the
            | evening, and a kitchen that had to wait for a manager would
            | simply keep selling what it has run out of.
            */
            Route::put('products/{product}/sold-out', [ProductController::class, 'toggleSoldOut'])
                ->whereNumber('product')
                ->name('products.sold-out');

            Route::put('products/{product}/status', [ProductController::class, 'toggleStatus'])
                ->name('products.status');
            Route::put('products/{product}/published', [ProductController::class, 'togglePublished'])
                ->name('products.published');
            Route::delete('products/{product}/image', [ProductController::class, 'destroyImage'])
                ->name('products.image.destroy');
            Route::delete('products/{product}/gallery/{image}', [ProductController::class, 'destroyGalleryImage'])
                ->name('products.gallery.destroy');
        });

        Route::delete('products/{product}', [ProductController::class, 'destroy'])
            ->middleware('permission:inventory.products.delete')
            ->name('products.destroy');

        /*
        | Brands, Units and Tax slabs. Catalogue masters, so none of them is
        | shop-scoped - the same brand and the same GST slab serve every
        | branch. All three follow the Categories shape exactly.
        */
        Route::middleware('permission:inventory.brands.view')->group(function () {
            Route::get('brands', [BrandController::class, 'index'])->name('brands.index');
            Route::get('brands/{brand}', [BrandController::class, 'show'])
                ->whereNumber('brand')
                ->name('brands.show');
        });

        Route::middleware('permission:inventory.brands.create')->group(function () {
            // Registered before the wildcard so "create" is not read as an id.
            Route::get('brands/create', [BrandController::class, 'create'])->name('brands.create');
            Route::post('brands', [BrandController::class, 'store'])->name('brands.store');
        });

        Route::middleware('permission:inventory.brands.edit')->group(function () {
            Route::get('brands/{brand}/edit', [BrandController::class, 'edit'])->name('brands.edit');
            Route::put('brands/{brand}', [BrandController::class, 'update'])->name('brands.update');
            Route::put('brands/{brand}/status', [BrandController::class, 'toggleStatus'])
                ->name('brands.status');
            Route::delete('brands/{brand}/logo', [BrandController::class, 'destroyLogo'])
                ->name('brands.logo.destroy');
        });

        Route::delete('brands/{brand}', [BrandController::class, 'destroy'])
            ->middleware('permission:inventory.brands.delete')
            ->name('brands.destroy');

        Route::middleware('permission:inventory.units.view')->group(function () {
            Route::get('units', [UnitController::class, 'index'])->name('units.index');
            Route::get('units/{unit}', [UnitController::class, 'show'])
                ->whereNumber('unit')
                ->name('units.show');
        });

        Route::middleware('permission:inventory.units.create')->group(function () {
            Route::get('units/create', [UnitController::class, 'create'])->name('units.create');
            Route::post('units', [UnitController::class, 'store'])->name('units.store');
        });

        Route::middleware('permission:inventory.units.edit')->group(function () {
            Route::get('units/{unit}/edit', [UnitController::class, 'edit'])->name('units.edit');
            Route::put('units/{unit}', [UnitController::class, 'update'])->name('units.update');
            Route::put('units/{unit}/status', [UnitController::class, 'toggleStatus'])
                ->name('units.status');
        });

        /*
        | Add-ons and modifiers (§8).
        |
        | A question and its answers are written together - see the
        | controller - so there is no separate options endpoint to guard.
        */
        Route::middleware('permission:inventory.modifiers.view')->group(function () {
            Route::get('modifiers', [ModifierController::class, 'index'])->name('modifiers.index');
            Route::get('modifiers/{modifier}', [ModifierController::class, 'show'])
                ->whereNumber('modifier')
                ->name('modifiers.show');
        });

        Route::middleware('permission:inventory.modifiers.create')->group(function () {
            Route::get('modifiers/create', [ModifierController::class, 'create'])->name('modifiers.create');
            Route::post('modifiers', [ModifierController::class, 'store'])->name('modifiers.store');
        });

        Route::middleware('permission:inventory.modifiers.edit')->group(function () {
            Route::get('modifiers/{modifier}/edit', [ModifierController::class, 'edit'])
                ->whereNumber('modifier')
                ->name('modifiers.edit');
            Route::put('modifiers/{modifier}', [ModifierController::class, 'update'])
                ->whereNumber('modifier')
                ->name('modifiers.update');
            Route::put('modifiers/{modifier}/status', [ModifierController::class, 'toggleStatus'])
                ->whereNumber('modifier')
                ->name('modifiers.status');
        });

        Route::delete('modifiers/{modifier}', [ModifierController::class, 'destroy'])
            ->whereNumber('modifier')
            ->middleware('permission:inventory.modifiers.delete')
            ->name('modifiers.destroy');

        Route::delete('units/{unit}', [UnitController::class, 'destroy'])
            ->middleware('permission:inventory.units.delete')
            ->name('units.destroy');

        // Filed under Finance rather than Inventory: a GST slab is an
        // accounting decision, and the people who set it are not the people
        // who name the products.
        Route::middleware('permission:finance.taxes.view')->group(function () {
            Route::get('taxes', [TaxRateController::class, 'index'])->name('taxes.index');
            Route::get('taxes/{tax}', [TaxRateController::class, 'show'])
                ->whereNumber('tax')
                ->name('taxes.show');
        });

        Route::middleware('permission:finance.taxes.create')->group(function () {
            Route::get('taxes/create', [TaxRateController::class, 'create'])->name('taxes.create');
            Route::post('taxes', [TaxRateController::class, 'store'])->name('taxes.store');
        });

        Route::middleware('permission:finance.taxes.edit')->group(function () {
            Route::get('taxes/{tax}/edit', [TaxRateController::class, 'edit'])->name('taxes.edit');
            Route::put('taxes/{tax}', [TaxRateController::class, 'update'])->name('taxes.update');
            Route::put('taxes/{tax}/status', [TaxRateController::class, 'toggleStatus'])
                ->name('taxes.status');
        });

        Route::delete('taxes/{tax}', [TaxRateController::class, 'destroy'])
            ->middleware('permission:finance.taxes.delete')
            ->name('taxes.destroy');

        /* Warehouses. Shop-scoped: a location belongs to one branch. */
        Route::middleware('permission:inventory.warehouses.view')->group(function () {
            Route::get('warehouses', [WarehouseController::class, 'index'])->name('warehouses.index');
            Route::get('warehouses/{warehouse}', [WarehouseController::class, 'show'])
                ->whereNumber('warehouse')
                ->name('warehouses.show');
        });

        Route::middleware('permission:inventory.warehouses.create')->group(function () {
            Route::get('warehouses/create', [WarehouseController::class, 'create'])
                ->name('warehouses.create');
            Route::post('warehouses', [WarehouseController::class, 'store'])->name('warehouses.store');
        });

        Route::middleware('permission:inventory.warehouses.edit')->group(function () {
            Route::get('warehouses/{warehouse}/edit', [WarehouseController::class, 'edit'])
                ->name('warehouses.edit');
            Route::put('warehouses/{warehouse}', [WarehouseController::class, 'update'])
                ->name('warehouses.update');
            Route::put('warehouses/{warehouse}/status', [WarehouseController::class, 'toggleStatus'])
                ->name('warehouses.status');
            Route::put('warehouses/{warehouse}/default', [WarehouseController::class, 'makeDefault'])
                ->name('warehouses.default');
        });

        Route::delete('warehouses/{warehouse}', [WarehouseController::class, 'destroy'])
            ->middleware('permission:inventory.warehouses.delete')
            ->name('warehouses.destroy');

        /*
        | The counter.
        |
        | One endpoint raises both the POS receipt and the manual invoice -
        | they are the same document. The elevated rights the SRS separates
        | out (discount override, credit sale) are checked inside the
        | controller rather than here, because whether they apply depends on
        | what was actually typed into the sale.
        */
        Route::get('pos', [PosController::class, 'terminal'])
            ->middleware('permission:pos.terminal.view')
            ->name('pos.terminal');

        Route::get('invoices/create', [PosController::class, 'manual'])
            ->middleware('permission:sales.invoices.create')
            ->name('pos.manual');

        Route::post('pos', [PosController::class, 'store'])
            ->middleware('permission:pos.terminal.create')
            ->name('pos.store');

        /*
        | Hold and resume (§6).
        |
        | Same right as completing a sale: anybody who may bill may put a
        | basket down and pick it up again. It is not an elevated action -
        | nothing is numbered, no stock moves and no ledger is written.
        |
        | `parked` is registered before the wildcard so "parked" is never read
        | as an id.
        */
        Route::middleware('permission:pos.terminal.create')->group(function () {
            Route::get('pos/parked', [PosController::class, 'parked'])->name('pos.parked');
            Route::post('pos/park', [PosController::class, 'park'])->name('pos.park');
            Route::post('pos/parked/{sale}/resume', [PosController::class, 'resume'])
                ->whereNumber('sale')
                ->name('pos.resume');
            Route::delete('pos/parked/{sale}', [PosController::class, 'discard'])
                ->whereNumber('sale')
                ->name('pos.discard');
        });

        /*
        | The barcode scanner module - see ScannerController and
        | app/Services/ScannerService.php. One endpoint for USB, Bluetooth
        | and camera scans alike; throttled because a stuck/looping scanner
        | (or a bug in scanner.js) should degrade gracefully, not hammer the
        | database.
        */
        Route::post('api/scanner/product', [ScannerController::class, 'product'])
            ->middleware(['throttle:120,1', 'permission:pos.terminal.view'])
            ->name('scanner.product');

        /*
        | Barcode/price sticker sheets. Nothing here is a document - see
        | BarcodeLabelController's docblock - so there is no store/show/index
        | pair, just a picker and a print action.
        */
        Route::get('labels', [BarcodeLabelController::class, 'index'])
            ->middleware('permission:pos.labels.view')
            ->name('labels.index');

        Route::post('labels/print', [BarcodeLabelController::class, 'print'])
            ->middleware('permission:pos.labels.print')
            ->name('labels.print');

        /*
        | Cash register / day close - one session per shop per business day.
        | See CashRegisterController's docblock.
        */
        Route::prefix('registers')->name('registers.')->group(function () {
            Route::get('export', [CashRegisterController::class, 'export'])
                ->middleware('permission:pos.registers.export')
                ->name('export');

            Route::middleware('permission:pos.registers.view')->group(function () {
                Route::get('/', [CashRegisterController::class, 'index'])->name('index');
                Route::get('{register}', [CashRegisterController::class, 'show'])
                    ->whereNumber('register')
                    ->name('show');
            });

            Route::post('/', [CashRegisterController::class, 'store'])
                ->middleware('permission:pos.registers.create')
                ->name('store');

            Route::put('{register}/close', [CashRegisterController::class, 'close'])
                ->whereNumber('register')
                ->middleware('permission:pos.registers.edit')
                ->name('close');

            Route::put('{register}/approve', [CashRegisterController::class, 'approve'])
                ->whereNumber('register')
                ->middleware('permission:pos.registers.approve')
                ->name('approve');
        });

        /*
        | Invoices, after the sale. Deliberately no edit route: an invoice
        | that has been handed over and taken stock off a shelf is corrected
        | by cancelling and re-raising, never by rewriting.
        */
        Route::get('invoices/export', [InvoiceController::class, 'export'])
            ->middleware('permission:sales.invoices.export')
            ->name('invoices.export');

        Route::middleware('permission:sales.invoices.view')->group(function () {
            Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
            Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])
                ->whereNumber('invoice')
                ->name('invoices.show');
        });

        Route::get('invoices/{invoice}/print', [InvoiceController::class, 'print'])
            ->whereNumber('invoice')
            ->middleware('permission:sales.invoices.print')
            ->name('invoices.print');

        Route::middleware('permission:sales.invoices.cancel')->group(function () {
            Route::get('invoices/{invoice}/cancel', [InvoiceController::class, 'cancelForm'])
                ->whereNumber('invoice')
                ->name('invoices.cancel.form');
            Route::put('invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])
                ->whereNumber('invoice')
                ->name('invoices.cancel');
        });

        /*
        | Purchase orders. An intention: nothing here moves stock or money,
        | and the order's status follows the receipts raised against it.
        */
        Route::prefix('purchase-orders')->name('purchase-orders.')->group(function () {
            Route::middleware('permission:purchasing.purchase_orders.view')->group(function () {
                Route::get('/', [PurchaseOrderController::class, 'index'])->name('index');
                Route::get('{order}', [PurchaseOrderController::class, 'show'])
                    ->whereNumber('order')
                    ->name('show');
            });

            Route::middleware('permission:purchasing.purchase_orders.create')->group(function () {
                // Registered before the wildcard so "create" is not read as an id.
                Route::get('create', [PurchaseOrderController::class, 'create'])->name('create');
                Route::post('/', [PurchaseOrderController::class, 'store'])->name('store');
            });

            Route::middleware('permission:purchasing.purchase_orders.edit')->group(function () {
                Route::get('{order}/edit', [PurchaseOrderController::class, 'edit'])->name('edit');
                Route::put('{order}', [PurchaseOrderController::class, 'update'])->name('update');
                Route::put('{order}/cancel', [PurchaseOrderController::class, 'cancel'])->name('cancel');
            });

            Route::put('{order}/approve', [PurchaseOrderController::class, 'approve'])
                ->middleware('permission:purchasing.purchase_orders.approve')
                ->name('approve');

            Route::delete('{order}', [PurchaseOrderController::class, 'destroy'])
                ->middleware('permission:purchasing.purchase_orders.delete')
                ->name('destroy');
        });

        /*
        | Goods receipts. Posting one is the point of no return - it is when
        | stock lands and the supplier is billed - so it is gated on
        | `approve` rather than on `create`.
        */
        Route::prefix('receipts')->name('receipts.')->group(function () {
            Route::get('export', [GoodsReceiptController::class, 'export'])
                ->middleware('permission:purchasing.receipts.view')
                ->name('export');

            Route::middleware('permission:purchasing.receipts.view')->group(function () {
                Route::get('/', [GoodsReceiptController::class, 'index'])->name('index');
                Route::get('{receipt}', [GoodsReceiptController::class, 'show'])
                    ->whereNumber('receipt')
                    ->name('show');
            });

            Route::middleware('permission:purchasing.receipts.create')->group(function () {
                Route::get('create', [GoodsReceiptController::class, 'create'])->name('create');
                Route::post('/', [GoodsReceiptController::class, 'store'])->name('store');
            });

            Route::middleware('permission:purchasing.receipts.edit')->group(function () {
                Route::get('{receipt}/edit', [GoodsReceiptController::class, 'edit'])->name('edit');
                Route::put('{receipt}', [GoodsReceiptController::class, 'update'])->name('update');
            });

            Route::put('{receipt}/post', [GoodsReceiptController::class, 'post'])
                ->middleware('permission:purchasing.receipts.approve')
                ->name('post');

            Route::middleware('permission:purchasing.receipts.delete')->group(function () {
                Route::get('{receipt}/cancel', [GoodsReceiptController::class, 'cancelForm'])
                    ->whereNumber('receipt')
                    ->name('cancel.form');
                Route::put('{receipt}/cancel', [GoodsReceiptController::class, 'cancel'])
                    ->whereNumber('receipt')
                    ->name('cancel');
                Route::delete('{receipt}', [GoodsReceiptController::class, 'destroy'])->name('destroy');
            });
        });

        /*
        | Purchase invoices - a bills register over posted receipts, not a
        | document of its own. See PurchaseInvoiceController's docblock.
        */
        Route::prefix('purchase-invoices')->name('purchase-invoices.')->group(function () {
            Route::get('export', [PurchaseInvoiceController::class, 'export'])
                ->middleware('permission:purchasing.bills.export')
                ->name('export');

            Route::get('/', [PurchaseInvoiceController::class, 'index'])
                ->middleware('permission:purchasing.bills.view')
                ->name('index');

            Route::get('{receipt}/print', [PurchaseInvoiceController::class, 'print'])
                ->whereNumber('receipt')
                ->middleware('permission:purchasing.bills.print')
                ->name('print');
        });

        /*
        | Purchase returns. The mirror of sales-returns, on the other side of
        | the counter - see PurchaseReturnController's docblock. Approving is
        | what moves anything, so it is gated on `approve` like every other
        | accept/reject pair in this file.
        */
        Route::prefix('purchase-returns')->name('purchase-returns.')->group(function () {
            Route::middleware('permission:purchasing.returns.view')->group(function () {
                Route::get('/', [PurchaseReturnController::class, 'index'])->name('index');
                Route::get('{return}', [PurchaseReturnController::class, 'show'])
                    ->whereNumber('return')
                    ->name('show');
            });

            Route::middleware('permission:purchasing.returns.create')->group(function () {
                // Registered before the wildcard so "create" is not read as an id.
                Route::get('create', [PurchaseReturnController::class, 'create'])->name('create');
                Route::post('/', [PurchaseReturnController::class, 'store'])->name('store');
            });

            Route::put('{return}/approve', [PurchaseReturnController::class, 'approve'])
                ->middleware('permission:purchasing.returns.approve')
                ->name('approve');

            Route::middleware('permission:purchasing.returns.reject')->group(function () {
                Route::get('{return}/reject', [PurchaseReturnController::class, 'rejectForm'])
                    ->whereNumber('return')
                    ->name('reject.form');
                Route::put('{return}/reject', [PurchaseReturnController::class, 'reject'])
                    ->whereNumber('return')
                    ->name('reject');
            });

            Route::delete('{return}', [PurchaseReturnController::class, 'destroy'])
                ->middleware('permission:purchasing.returns.delete')
                ->name('destroy');
        });

        /*
        | Payments. No update route, deliberately: a recorded payment is a
        | statement about money that changed hands, and correcting it means
        | reversing it so both entries stay visible.
        |
        | Bounce and reverse are gated on `adjust` rather than `edit` -
        | both rewrite a customer's balance, which the SRS wants granted
        | separately and audited.
        */
        Route::prefix('payments')->name('payments.')->group(function () {
            Route::get('export', [PaymentController::class, 'export'])
                ->middleware('permission:finance.payments.export')
                ->name('export');

            Route::middleware('permission:finance.payments.view')->group(function () {
                Route::get('/', [PaymentController::class, 'index'])->name('index');
                Route::get('{payment}', [PaymentController::class, 'show'])
                    ->whereNumber('payment')
                    ->name('show');
                Route::get('{payment}/action', [PaymentController::class, 'actionForm'])
                    ->whereNumber('payment')
                    ->name('action');
            });

            Route::middleware('permission:finance.payments.create')->group(function () {
                // Registered before the wildcard so "create" is not read as an id.
                Route::get('create', [PaymentController::class, 'create'])->name('create');
                Route::post('/', [PaymentController::class, 'store'])->name('store');
            });

            Route::put('{payment}/clear', [PaymentController::class, 'clear'])
                ->middleware('permission:finance.payments.approve')
                ->name('clear');

            Route::middleware('permission:finance.payments.adjust')->group(function () {
                Route::put('{payment}/bounce', [PaymentController::class, 'bounce'])->name('bounce');
                Route::put('{payment}/reverse', [PaymentController::class, 'reverse'])->name('reverse');
            });
        });

        /*
        | Payment reminders. Mostly a log; the three write actions are the
        | ones a person needs when the nightly run did not do what they
        | wanted.
        */
        Route::prefix('reminders')->name('reminders.')->group(function () {
            Route::get('export', [PaymentReminderController::class, 'export'])
                ->middleware('permission:crm.reminders.export')
                ->name('export');

            Route::middleware('permission:crm.reminders.view')->group(function () {
                Route::get('/', [PaymentReminderController::class, 'index'])->name('index');
                Route::get('{reminder}', [PaymentReminderController::class, 'show'])
                    ->whereNumber('reminder')
                    ->name('show');
            });

            // Raising the schedule creates rows, so it belongs to `create`.
            Route::post('run', [PaymentReminderController::class, 'runScheduler'])
                ->middleware('permission:crm.reminders.create')
                ->name('run');

            Route::put('{reminder}/send', [PaymentReminderController::class, 'send'])
                ->middleware('permission:crm.reminders.edit')
                ->name('send');

            Route::put('{reminder}/cancel', [PaymentReminderController::class, 'cancel'])
                ->middleware('permission:crm.reminders.delete')
                ->name('cancel');
        });

        /*
        | Expenses. Approving is what records the money going out, so it is
        | gated on `approve` rather than on `edit` - an expense somebody
        | typed in should not appear in the day's cash until it is agreed.
        */
        Route::prefix('expenses')->name('expenses.')->group(function () {
            Route::get('export', [ExpenseController::class, 'export'])
                ->middleware('permission:finance.expenses.export')
                ->name('export');

            Route::middleware('permission:finance.expenses.view')->group(function () {
                Route::get('/', [ExpenseController::class, 'index'])->name('index');
                Route::get('{expense}', [ExpenseController::class, 'show'])
                    ->whereNumber('expense')
                    ->name('show');
            });

            Route::middleware('permission:finance.expenses.create')->group(function () {
                // Registered before the wildcard so "create" is not read as an id.
                Route::get('create', [ExpenseController::class, 'create'])->name('create');
                Route::post('/', [ExpenseController::class, 'store'])->name('store');
            });

            Route::middleware('permission:finance.expenses.edit')->group(function () {
                Route::get('{expense}/edit', [ExpenseController::class, 'edit'])->name('edit');
                Route::put('{expense}', [ExpenseController::class, 'update'])->name('update');
            });

            Route::middleware('permission:finance.expenses.approve')->group(function () {
                Route::put('{expense}/approve', [ExpenseController::class, 'approve'])->name('approve');
                Route::put('{expense}/reject', [ExpenseController::class, 'reject'])->name('reject');
            });

            Route::delete('{expense}', [ExpenseController::class, 'destroy'])
                ->middleware('permission:finance.expenses.delete')
                ->name('destroy');
        });

        /* The customer account: statement, dues, write-offs. */
        Route::middleware('permission:crm.ledger.view')->group(function () {
            Route::get('ledger', [CustomerLedgerController::class, 'index'])->name('ledger.index');
        });

        Route::get('ledger/{customer}/statement', [CustomerLedgerController::class, 'statement'])
            ->whereNumber('customer')
            ->middleware('permission:crm.ledger.print')
            ->name('ledger.statement');

        Route::get('dues/export', [CustomerLedgerController::class, 'export'])
            ->middleware('permission:crm.dues.export')
            ->name('dues.export');

        Route::get('dues', [CustomerLedgerController::class, 'dues'])
            ->middleware('permission:crm.dues.view')
            ->name('dues.index');

        // Its own right: this is the one operation that makes a debt vanish
        // without anyone paying it.
        Route::middleware('permission:crm.dues.write_off')->group(function () {
            Route::get('dues/{customer}/write-off', [CustomerLedgerController::class, 'writeOffForm'])
                ->whereNumber('customer')
                ->name('dues.write-off.form');
            Route::post('dues/{customer}/write-off', [CustomerLedgerController::class, 'writeOff'])
                ->whereNumber('customer')
                ->name('dues.write-off');
        });

        /*
        | Sales returns. Approving is what moves anything - until then it is
        | a claim, not an event - so it is gated on `approve`, and only
        | goods marked resalable go back into sellable stock.
        */
        Route::prefix('sales-returns')->name('sales-returns.')->group(function () {
            Route::middleware('permission:sales.returns.view')->group(function () {
                Route::get('/', [SalesReturnController::class, 'index'])->name('index');
                Route::get('{return}', [SalesReturnController::class, 'show'])
                    ->whereNumber('return')
                    ->name('show');
            });

            Route::middleware('permission:sales.returns.create')->group(function () {
                // Registered before the wildcard so "create" is not read as an id.
                Route::get('create', [SalesReturnController::class, 'create'])->name('create');
                Route::post('/', [SalesReturnController::class, 'store'])->name('store');
            });

            Route::put('{return}/approve', [SalesReturnController::class, 'approve'])
                ->middleware('permission:sales.returns.approve')
                ->name('approve');

            Route::middleware('permission:sales.returns.reject')->group(function () {
                Route::get('{return}/reject', [SalesReturnController::class, 'rejectForm'])
                    ->whereNumber('return')
                    ->name('reject.form');
                Route::put('{return}/reject', [SalesReturnController::class, 'reject'])
                    ->whereNumber('return')
                    ->name('reject');
            });

            Route::delete('{return}', [SalesReturnController::class, 'destroy'])
                ->middleware('permission:sales.returns.delete')
                ->name('destroy');
        });

        /*
        | Online orders placed on a shop's storefront. The document itself is
        | App\Models\Order, not Invoice - see its docblock. `approve` gates
        | both confirm and mark-paid: mark-paid is the one that raises the
        | real Invoice and takes the stock, so it needs the same authority as
        | accepting the order in the first place.
        */
        Route::prefix('orders')->name('orders.')->group(function () {
            Route::get('export', [OnlineOrderController::class, 'export'])
                ->middleware('permission:sales.orders.export')
                ->name('export');

            Route::middleware('permission:sales.orders.view')->group(function () {
                Route::get('/', [OnlineOrderController::class, 'index'])->name('index');
                Route::get('{order}', [OnlineOrderController::class, 'show'])
                    ->whereNumber('order')
                    ->name('show');
            });

            Route::get('{order}/print', [OnlineOrderController::class, 'print'])
                ->whereNumber('order')
                ->middleware('permission:sales.orders.print')
                ->name('print');

            Route::middleware('permission:sales.orders.approve')->group(function () {
                Route::put('{order}/confirm', [OnlineOrderController::class, 'confirm'])
                    ->whereNumber('order')
                    ->name('confirm');
                Route::put('{order}/mark-paid', [OnlineOrderController::class, 'markPaid'])
                    ->whereNumber('order')
                    ->name('mark-paid');
                Route::put('{order}/deliver', [OnlineOrderController::class, 'markDelivered'])
                    ->whereNumber('order')
                    ->name('deliver');
            });

            Route::put('{order}/status', [OnlineOrderController::class, 'updateStatus'])
                ->whereNumber('order')
                ->middleware('permission:sales.orders.edit')
                ->name('status');

            Route::put('{order}/cancel', [OnlineOrderController::class, 'cancel'])
                ->whereNumber('order')
                ->middleware('permission:sales.orders.reject')
                ->name('cancel');
        });

        /*
        | Coupons & offers, applied at storefront checkout - App\Models\Coupon.
        */
        Route::prefix('coupons')->name('coupons.')->group(function () {
            Route::get('export', [CouponController::class, 'export'])
                ->middleware('permission:sales.coupons.export')
                ->name('export');

            Route::middleware('permission:sales.coupons.view')->group(function () {
                Route::get('/', [CouponController::class, 'index'])->name('index');
                Route::get('{coupon}', [CouponController::class, 'show'])
                    ->whereNumber('coupon')
                    ->name('show');
            });

            Route::middleware('permission:sales.coupons.create')->group(function () {
                Route::get('create', [CouponController::class, 'create'])->name('create');
                Route::post('/', [CouponController::class, 'store'])->name('store');
            });

            Route::middleware('permission:sales.coupons.edit')->group(function () {
                Route::get('{coupon}/edit', [CouponController::class, 'edit'])
                    ->whereNumber('coupon')
                    ->name('edit');
                Route::put('{coupon}', [CouponController::class, 'update'])
                    ->whereNumber('coupon')
                    ->name('update');
                Route::put('{coupon}/status', [CouponController::class, 'toggleStatus'])
                    ->whereNumber('coupon')
                    ->name('status');
            });

            Route::delete('{coupon}', [CouponController::class, 'destroy'])
                ->whereNumber('coupon')
                ->middleware('permission:sales.coupons.delete')
                ->name('destroy');
        });

        /*
        | Stock on hand. Read-only: every quantity change belongs to a
        | document, and going through one is what leaves the explanation.
        */
        Route::get('stock/export', [StockController::class, 'export'])
            ->middleware('permission:inventory.stock.export')
            ->name('stock.export');

        Route::middleware('permission:inventory.stock.view')->group(function () {
            Route::get('stock', [StockController::class, 'index'])->name('stock.index');
            Route::get('stock/{product}/movements', [StockController::class, 'movements'])
                ->whereNumber('product')
                ->name('stock.movements');
        });

        /* Batches and expiry. */
        Route::get('batches/export', [BatchController::class, 'export'])
            ->middleware('permission:inventory.batches.export')
            ->name('batches.export');

        Route::middleware('permission:inventory.batches.view')->group(function () {
            Route::get('batches', [BatchController::class, 'index'])->name('batches.index');
            Route::get('batches/{batch}', [BatchController::class, 'show'])
                ->whereNumber('batch')
                ->name('batches.show');
        });

        Route::middleware('permission:inventory.batches.create')->group(function () {
            Route::get('batches/create', [BatchController::class, 'create'])->name('batches.create');
            Route::post('batches', [BatchController::class, 'store'])->name('batches.store');
        });

        Route::middleware('permission:inventory.batches.edit')->group(function () {
            Route::get('batches/{batch}/edit', [BatchController::class, 'edit'])->name('batches.edit');
            Route::put('batches/{batch}', [BatchController::class, 'update'])->name('batches.update');
            Route::put('batches/{batch}/status', [BatchController::class, 'toggleStatus'])
                ->name('batches.status');
        });

        Route::delete('batches/{batch}', [BatchController::class, 'destroy'])
            ->middleware('permission:inventory.batches.delete')
            ->name('batches.destroy');

        /*
        | Stock adjustments. Full-page documents rather than modals: line
        | items need room, and a reference is something people quote.
        |
        | Approve and reject are gated on `approve`, not `edit`. Applying an
        | adjustment is the irreversible act and deserves its own right.
        */
        Route::prefix('stock-adjustments')->name('stock-adjustments.')->group(function () {
            Route::middleware('permission:inventory.adjustments.view')->group(function () {
                Route::get('/', [StockAdjustmentController::class, 'index'])->name('index');
                // Warehouse-scoped product lookup for the count sheet.
                Route::get('lookup', [StockAdjustmentController::class, 'lookup'])->name('lookup');
                Route::get('{adjustment}', [StockAdjustmentController::class, 'show'])
                    ->whereNumber('adjustment')
                    ->name('show');
            });

            Route::middleware('permission:inventory.adjustments.create')->group(function () {
                // Registered before the wildcard so "create" is not read as an id.
                Route::get('create', [StockAdjustmentController::class, 'create'])->name('create');
                Route::post('/', [StockAdjustmentController::class, 'store'])->name('store');
            });

            Route::middleware('permission:inventory.adjustments.edit')->group(function () {
                Route::get('{adjustment}/edit', [StockAdjustmentController::class, 'edit'])->name('edit');
                Route::put('{adjustment}', [StockAdjustmentController::class, 'update'])->name('update');
                Route::put('{adjustment}/cancel', [StockAdjustmentController::class, 'cancel'])->name('cancel');
            });

            Route::put('{adjustment}/approve', [StockAdjustmentController::class, 'approve'])
                ->middleware('permission:inventory.adjustments.approve')
                ->name('approve');

            Route::put('{adjustment}/reject', [StockAdjustmentController::class, 'reject'])
                ->middleware('permission:inventory.adjustments.reject')
                ->name('reject');

            Route::delete('{adjustment}', [StockAdjustmentController::class, 'destroy'])
                ->middleware('permission:inventory.adjustments.delete')
                ->name('destroy');
        });

        /*
        | Stock transfers. Same document shape as adjustments, with a longer
        | lifecycle: approve, dispatch, receive.
        |
        | `receive` is gated on `edit` rather than `approve` because it is
        | the receiving shop's clerk who books a consignment in, and the
        | controller separately refuses anyone without access to the
        | destination shop.
        */
        Route::prefix('stock-transfers')->name('stock-transfers.')->group(function () {
            Route::middleware('permission:inventory.transfers.view')->group(function () {
                Route::get('/', [StockTransferController::class, 'index'])->name('index');
                Route::get('lookup', [StockTransferController::class, 'lookup'])->name('lookup');
                Route::get('{transfer}', [StockTransferController::class, 'show'])
                    ->whereNumber('transfer')
                    ->name('show');
            });

            Route::middleware('permission:inventory.transfers.create')->group(function () {
                // Registered before the wildcard so "create" is not read as an id.
                Route::get('create', [StockTransferController::class, 'create'])->name('create');
                Route::post('/', [StockTransferController::class, 'store'])->name('store');
            });

            Route::middleware('permission:inventory.transfers.edit')->group(function () {
                Route::get('{transfer}/edit', [StockTransferController::class, 'edit'])->name('edit');
                Route::put('{transfer}', [StockTransferController::class, 'update'])->name('update');
                Route::put('{transfer}/dispatch', [StockTransferController::class, 'dispatchGoods'])
                    ->name('dispatch');
                Route::put('{transfer}/receive', [StockTransferController::class, 'receive'])
                    ->name('receive');
                Route::put('{transfer}/cancel', [StockTransferController::class, 'cancel'])->name('cancel');
            });

            Route::put('{transfer}/approve', [StockTransferController::class, 'approve'])
                ->middleware('permission:inventory.transfers.approve')
                ->name('approve');

            Route::put('{transfer}/reject', [StockTransferController::class, 'reject'])
                ->middleware('permission:inventory.transfers.reject')
                ->name('reject');

            Route::delete('{transfer}', [StockTransferController::class, 'destroy'])
                ->middleware('permission:inventory.transfers.delete')
                ->name('destroy');
        });

        /*
        | Customers. Shop-scoped, and the one master the counter reaches for
        | mid-sale - hence the JSON lookup endpoint alongside the CRUD.
        */
        Route::get('customers/export', [CustomerController::class, 'export'])
            ->middleware('permission:crm.customers.export')
            ->name('customers.export');

        Route::middleware('permission:crm.customers.view')->group(function () {
            Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
            // Type-ahead for POS and invoice forms; same right as reading the list.
            Route::get('customers/lookup', [CustomerController::class, 'lookup'])->name('customers.lookup');
            Route::get('customers/{customer}', [CustomerController::class, 'show'])
                ->whereNumber('customer')
                ->name('customers.show');
        });

        Route::middleware('permission:crm.customers.create')->group(function () {
            // Registered before the wildcard so "create" is not read as an id.
            Route::get('customers/create', [CustomerController::class, 'create'])->name('customers.create');
            Route::post('customers', [CustomerController::class, 'store'])->name('customers.store');
        });

        Route::middleware('permission:crm.customers.edit')->group(function () {
            Route::get('customers/{customer}/edit', [CustomerController::class, 'edit'])
                ->name('customers.edit');
            Route::put('customers/{customer}', [CustomerController::class, 'update'])
                ->name('customers.update');
            Route::put('customers/{customer}/status', [CustomerController::class, 'toggleStatus'])
                ->name('customers.status');
            Route::delete('customers/{customer}/image', [CustomerController::class, 'destroyImage'])
                ->name('customers.image.destroy');
        });

        Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])
            ->middleware('permission:crm.customers.delete')
            ->name('customers.destroy');

        /* Suppliers. Same shape as customers, on the purchasing side. */
        Route::get('suppliers/export', [SupplierController::class, 'export'])
            ->middleware('permission:purchasing.suppliers.export')
            ->name('suppliers.export');

        Route::middleware('permission:purchasing.suppliers.view')->group(function () {
            Route::get('suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
            Route::get('suppliers/lookup', [SupplierController::class, 'lookup'])->name('suppliers.lookup');
            Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])
                ->whereNumber('supplier')
                ->name('suppliers.show');
        });

        Route::middleware('permission:purchasing.suppliers.create')->group(function () {
            Route::get('suppliers/create', [SupplierController::class, 'create'])->name('suppliers.create');
            Route::post('suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
        });

        Route::middleware('permission:purchasing.suppliers.edit')->group(function () {
            Route::get('suppliers/{supplier}/edit', [SupplierController::class, 'edit'])
                ->name('suppliers.edit');
            Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])
                ->name('suppliers.update');
            Route::put('suppliers/{supplier}/status', [SupplierController::class, 'toggleStatus'])
                ->name('suppliers.status');
        });

        Route::delete('suppliers/{supplier}', [SupplierController::class, 'destroy'])
            ->middleware('permission:purchasing.suppliers.delete')
            ->name('suppliers.destroy');

        /*
        | Services and FAQs. Same shape as Categories: every screen answers
        | a fragment when asked for one, so both run inside modals and an
        | AJAX list.
        */
        Route::middleware('permission:content.services.view')->group(function () {
            Route::get('services', [ServiceController::class, 'index'])->name('services.index');
            Route::get('services/{service}', [ServiceController::class, 'show'])
                ->whereNumber('service')
                ->name('services.show');
        });

        Route::middleware('permission:content.services.create')->group(function () {
            // Registered before the wildcard so "create" is not read as an id.
            Route::get('services/create', [ServiceController::class, 'create'])->name('services.create');
            Route::post('services', [ServiceController::class, 'store'])->name('services.store');
        });

        Route::middleware('permission:content.services.edit')->group(function () {
            Route::get('services/{service}/edit', [ServiceController::class, 'edit'])->name('services.edit');
            Route::put('services/{service}', [ServiceController::class, 'update'])->name('services.update');
            Route::put('services/{service}/status', [ServiceController::class, 'toggleStatus'])
                ->name('services.status');
            Route::delete('services/{service}/image', [ServiceController::class, 'destroyImage'])
                ->name('services.image.destroy');
        });

        Route::delete('services/{service}', [ServiceController::class, 'destroy'])
            ->middleware('permission:content.services.delete')
            ->name('services.destroy');

        /*
        | Collections. Media is a device x position matrix, so the remove
        | endpoint names the cell rather than a file id.
        */
        Route::middleware('permission:content.collections.view')->group(function () {
            Route::get('collections', [CollectionController::class, 'index'])->name('collections.index');
            Route::get('collections/{collection}', [CollectionController::class, 'show'])
                ->whereNumber('collection')
                ->name('collections.show');
        });

        Route::middleware('permission:content.collections.create')->group(function () {
            // Registered before the wildcard so "create" is not read as an id.
            Route::get('collections/create', [CollectionController::class, 'create'])
                ->name('collections.create');
            Route::post('collections', [CollectionController::class, 'store'])->name('collections.store');
        });

        Route::middleware('permission:content.collections.edit')->group(function () {
            Route::get('collections/{collection}/edit', [CollectionController::class, 'edit'])
                ->name('collections.edit');
            Route::put('collections/{collection}', [CollectionController::class, 'update'])
                ->name('collections.update');
            Route::put('collections/{collection}/status', [CollectionController::class, 'toggleStatus'])
                ->name('collections.status');
            Route::put('collections/{collection}/featured', [CollectionController::class, 'toggleFeatured'])
                ->name('collections.featured');
            Route::delete('collections/{collection}/media/{device}/{position}',
                [CollectionController::class, 'destroyMedia'])->name('collections.media.destroy');
        });

        Route::delete('collections/{collection}', [CollectionController::class, 'destroy'])
            ->middleware('permission:content.collections.delete')
            ->name('collections.destroy');

        /* Events. */
        Route::middleware('permission:content.events.view')->group(function () {
            Route::get('events', [EventController::class, 'index'])->name('events.index');
            Route::get('events/{event}', [EventController::class, 'show'])
                ->whereNumber('event')
                ->name('events.show');
        });

        Route::middleware('permission:content.events.create')->group(function () {
            // Registered before the wildcard so "create" is not read as an id.
            Route::get('events/create', [EventController::class, 'create'])->name('events.create');
            Route::post('events', [EventController::class, 'store'])->name('events.store');
        });

        Route::middleware('permission:content.events.edit')->group(function () {
            Route::get('events/{event}/edit', [EventController::class, 'edit'])->name('events.edit');
            Route::put('events/{event}', [EventController::class, 'update'])->name('events.update');
            Route::put('events/{event}/status', [EventController::class, 'toggleStatus'])
                ->name('events.status');
            Route::delete('events/{event}/image', [EventController::class, 'destroyImage'])
                ->name('events.image.destroy');
        });

        Route::delete('events/{event}', [EventController::class, 'destroy'])
            ->middleware('permission:content.events.delete')
            ->name('events.destroy');

        /*
        | Blog. Status is three-state (draft/published/inactive), so it is a
        | setStatus endpoint rather than a toggle; Featured is a real toggle.
        */
        Route::middleware('permission:content.blogs.view')->group(function () {
            Route::get('blogs', [BlogController::class, 'index'])->name('blogs.index');
            Route::get('blogs/{blog}', [BlogController::class, 'show'])
                ->whereNumber('blog')
                ->name('blogs.show');
        });

        Route::middleware('permission:content.blogs.create')->group(function () {
            // Registered before the wildcard so "create" is not read as an id.
            Route::get('blogs/create', [BlogController::class, 'create'])->name('blogs.create');
            Route::post('blogs', [BlogController::class, 'store'])->name('blogs.store');
        });

        Route::middleware('permission:content.blogs.edit')->group(function () {
            Route::get('blogs/{blog}/edit', [BlogController::class, 'edit'])->name('blogs.edit');
            Route::put('blogs/{blog}', [BlogController::class, 'update'])->name('blogs.update');
            Route::put('blogs/{blog}/status', [BlogController::class, 'setStatus'])->name('blogs.status');
            Route::put('blogs/{blog}/featured', [BlogController::class, 'toggleFeatured'])
                ->name('blogs.featured');
            Route::delete('blogs/{blog}/image', [BlogController::class, 'destroyImage'])
                ->name('blogs.image.destroy');
        });

        Route::delete('blogs/{blog}', [BlogController::class, 'destroy'])
            ->middleware('permission:content.blogs.delete')
            ->name('blogs.destroy');

        /* Instagram reels. */
        Route::middleware('permission:content.reels.view')->group(function () {
            Route::get('reels', [ReelController::class, 'index'])->name('reels.index');
            Route::get('reels/{reel}', [ReelController::class, 'show'])
                ->whereNumber('reel')
                ->name('reels.show');
        });

        Route::middleware('permission:content.reels.create')->group(function () {
            Route::get('reels/create', [ReelController::class, 'create'])->name('reels.create');
            Route::post('reels', [ReelController::class, 'store'])->name('reels.store');
        });

        Route::middleware('permission:content.reels.edit')->group(function () {
            Route::get('reels/{reel}/edit', [ReelController::class, 'edit'])->name('reels.edit');
            Route::put('reels/{reel}', [ReelController::class, 'update'])->name('reels.update');
            Route::put('reels/{reel}/status', [ReelController::class, 'toggleStatus'])->name('reels.status');
            Route::delete('reels/{reel}/thumbnail', [ReelController::class, 'destroyThumbnail'])
                ->name('reels.thumbnail.destroy');
        });

        Route::delete('reels/{reel}', [ReelController::class, 'destroy'])
            ->middleware('permission:content.reels.delete')
            ->name('reels.destroy');

        /* Instagram posts. */
        Route::middleware('permission:content.instagram.view')->group(function () {
            Route::get('instagram', [InstagramPostController::class, 'index'])->name('instagram.index');
            Route::get('instagram/{instagram}', [InstagramPostController::class, 'show'])
                ->whereNumber('instagram')
                ->name('instagram.show');
        });

        Route::middleware('permission:content.instagram.create')->group(function () {
            Route::get('instagram/create', [InstagramPostController::class, 'create'])
                ->name('instagram.create');
            Route::post('instagram', [InstagramPostController::class, 'store'])->name('instagram.store');
        });

        Route::middleware('permission:content.instagram.edit')->group(function () {
            Route::get('instagram/{instagram}/edit', [InstagramPostController::class, 'edit'])
                ->name('instagram.edit');
            Route::put('instagram/{instagram}', [InstagramPostController::class, 'update'])
                ->name('instagram.update');
            Route::put('instagram/{instagram}/status', [InstagramPostController::class, 'toggleStatus'])
                ->name('instagram.status');
            Route::delete('instagram/{instagram}/image', [InstagramPostController::class, 'destroyImage'])
                ->name('instagram.image.destroy');
        });

        Route::delete('instagram/{instagram}', [InstagramPostController::class, 'destroy'])
            ->middleware('permission:content.instagram.delete')
            ->name('instagram.destroy');

        Route::middleware('permission:content.sliders.view')->group(function () {
            Route::get('sliders', [SliderController::class, 'index'])->name('sliders.index');
            Route::get('sliders/{slider}', [SliderController::class, 'show'])
                ->whereNumber('slider')
                ->name('sliders.show');
        });

        Route::middleware('permission:content.sliders.create')->group(function () {
            // Registered before the wildcard so "create" is not read as an id.
            Route::get('sliders/create', [SliderController::class, 'create'])->name('sliders.create');
            Route::post('sliders', [SliderController::class, 'store'])->name('sliders.store');
            // Duplicate makes a new row, so it belongs to `create`.
            Route::post('sliders/{slider}/duplicate', [SliderController::class, 'duplicate'])
                ->name('sliders.duplicate');
        });

        Route::middleware('permission:content.sliders.edit')->group(function () {
            Route::get('sliders/{slider}/edit', [SliderController::class, 'edit'])->name('sliders.edit');
            Route::put('sliders/{slider}', [SliderController::class, 'update'])->name('sliders.update');
            Route::put('sliders/{slider}/status', [SliderController::class, 'toggleStatus'])
                ->name('sliders.status');
            Route::delete('sliders/{slider}/media/{slot}', [SliderController::class, 'destroyMedia'])
                ->name('sliders.media.destroy');
        });

        Route::delete('sliders/{slider}', [SliderController::class, 'destroy'])
            ->middleware('permission:content.sliders.delete')
            ->name('sliders.destroy');

        Route::middleware('permission:content.faqs.view')->group(function () {
            Route::get('faqs', [FaqController::class, 'index'])->name('faqs.index');
            Route::get('faqs/{faq}', [FaqController::class, 'show'])
                ->whereNumber('faq')
                ->name('faqs.show');
        });

        Route::middleware('permission:content.faqs.create')->group(function () {
            Route::get('faqs/create', [FaqController::class, 'create'])->name('faqs.create');
            Route::post('faqs', [FaqController::class, 'store'])->name('faqs.store');
        });

        Route::middleware('permission:content.faqs.edit')->group(function () {
            Route::get('faqs/{faq}/edit', [FaqController::class, 'edit'])->name('faqs.edit');
            Route::put('faqs/{faq}', [FaqController::class, 'update'])->name('faqs.update');
            Route::put('faqs/{faq}/status', [FaqController::class, 'toggleStatus'])->name('faqs.status');
        });

        Route::delete('faqs/{faq}', [FaqController::class, 'destroy'])
            ->middleware('permission:content.faqs.delete')
            ->name('faqs.destroy');

        /*
        | Email delivery log. Read-only apart from pruning: the rows are
        | written by the mail listeners, and nothing here should be able to
        | rewrite a record of what was sent.
        */
        Route::prefix('email')->name('email.')->group(function () {

            /*
            | Email templates. A template is a starting point: using one
            | copies its content into a new campaign, so `use` is gated on
            | the right to create a campaign rather than merely to read a
            | template.
            */
            Route::middleware('permission:email.templates.view')->group(function () {
                Route::get('templates', [EmailTemplateController::class, 'index'])
                    ->name('templates.index');
                Route::get('templates/{template}', [EmailTemplateController::class, 'show'])
                    ->whereNumber('template')
                    ->name('templates.show');
                // Rendered into a sandboxed iframe by the preview modal.
                Route::get('templates/{template}/preview', [EmailTemplateController::class, 'preview'])
                    ->whereNumber('template')
                    ->name('templates.preview');
                Route::get('templates/{template}/content', [EmailTemplateController::class, 'content'])
                    ->whereNumber('template')
                    ->name('templates.content');
            });

            Route::middleware('permission:email.templates.create')->group(function () {
                // Registered before the wildcard so "create" is not read as an id.
                Route::get('templates/create', [EmailTemplateController::class, 'create'])
                    ->name('templates.create');
                Route::post('templates', [EmailTemplateController::class, 'store'])
                    ->name('templates.store');
                // Duplicate makes a new row, so it belongs to `create`.
                Route::post('templates/{template}/duplicate', [EmailTemplateController::class, 'duplicate'])
                    ->name('templates.duplicate');
            });

            Route::middleware('permission:email.templates.edit')->group(function () {
                Route::get('templates/{template}/edit', [EmailTemplateController::class, 'edit'])
                    ->name('templates.edit');
                Route::put('templates/{template}', [EmailTemplateController::class, 'update'])
                    ->name('templates.update');
                Route::put('templates/{template}/status', [EmailTemplateController::class, 'toggleStatus'])
                    ->name('templates.status');

                Route::get('templates/{template}/test', [EmailTemplateController::class, 'testForm'])
                    ->name('templates.test');
                Route::post('templates/{template}/test', [EmailTemplateController::class, 'sendTest'])
                    ->name('templates.test.send');
            });

            Route::delete('templates/{template}', [EmailTemplateController::class, 'destroy'])
                ->middleware('permission:email.templates.delete')
                ->name('templates.destroy');

            Route::middleware('permission:email.logs.view')->group(function () {
                Route::get('logs', [EmailLogController::class, 'index'])->name('logs.index');
                Route::get('logs/{log}', [EmailLogController::class, 'show'])
                    ->whereNumber('log')
                    ->name('logs.show');
            });

            Route::get('logs/export', [EmailLogController::class, 'export'])
                ->middleware('permission:email.logs.export')
                ->name('logs.export');

            Route::delete('logs', [EmailLogController::class, 'destroy'])
                ->middleware('permission:email.logs.delete')
                ->name('logs.destroy');
        });

        /*
        | Reports.
        |
        | One controller for all of them - they share the date range, the
        | shop scope, the export shape and the permission pattern, and each
        | report declares what it is once in ReportController::REPORTS. The
        | per-report permission is checked inside, because the route cannot
        | know which one it is until it reads the parameter.
        */
        Route::get('reports', [ReportController::class, 'index'])->name('reports.index');

        Route::get('reports/{report}/export', [ReportController::class, 'export'])
            ->where('report', '[a-z\-]+')
            ->name('reports.export');

        Route::get('reports/{report}', [ReportController::class, 'show'])
            ->where('report', '[a-z\-]+')
            ->name('reports.show');

        Route::middleware('permission:settings.roles.view')->group(function () {
            Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
            Route::get('roles/{role}/edit', [RoleController::class, 'edit'])->name('roles.edit');
        });

        Route::middleware('permission:settings.roles.create')->group(function () {
            Route::get('roles/create', [RoleController::class, 'create'])->name('roles.create');
            Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
            Route::post('roles/{role}/clone', [RoleController::class, 'clone'])->name('roles.clone');
        });

        Route::middleware('permission:settings.roles.edit')->group(function () {
            Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
            Route::post('roles/{role}/permissions', [RoleController::class, 'sync'])->name('roles.permissions');
        });

        Route::delete('roles/{role}', [RoleController::class, 'destroy'])
            ->middleware('permission:settings.roles.delete')
            ->name('roles.destroy');

        Route::middleware('permission:settings.users.view')->group(function () {
            Route::get('users', [UserController::class, 'index'])->name('users.index');
            Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        });

        Route::get('users/export', [UserController::class, 'export'])
            ->middleware('permission:settings.users.export')
            ->name('users.export');

        Route::middleware('permission:settings.users.create')->group(function () {
            Route::get('users/create', [UserController::class, 'create'])->name('users.create');
            Route::post('users', [UserController::class, 'store'])->name('users.store');
        });

        Route::middleware('permission:settings.users.edit')->group(function () {
            Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
            Route::post('users/{user}/permissions', [UserController::class, 'syncPermissions'])
                ->name('users.permissions');
            // Fresh link when the first email bounced or the old one expired.
            Route::post('users/{user}/welcome', [UserController::class, 'resendWelcome'])
                ->name('users.welcome');
        });

        /*
        | Security side of a user. Split from the CRUD permissions above:
        | seeing where someone is signed in, and ending it, is a different
        | responsibility from editing their name.
        */
        Route::middleware('permission:settings.sessions.view')->group(function () {
            Route::get('users/{user}/security', [UserSecurityController::class, 'show'])
                ->name('users.security');
        });

        Route::put('users/{user}/status', [UserSecurityController::class, 'toggleStatus'])
            ->middleware('permission:settings.sessions.edit')
            ->name('users.status');

        Route::middleware('permission:settings.sessions.delete')->group(function () {
            Route::delete('users/{user}/sessions/{session}', [UserSecurityController::class, 'destroySession'])
                ->name('users.sessions.destroy');
            Route::delete('users/{user}/sessions', [UserSecurityController::class, 'destroyAllSessions'])
                ->name('users.sessions.destroy-all');
            Route::post('users/{user}/blocked-ips', [UserSecurityController::class, 'blockIp'])
                ->name('users.ips.block');
            Route::delete('users/{user}/blocked-ips/{block}', [UserSecurityController::class, 'unblockIp'])
                ->name('users.ips.unblock');
        });

        Route::middleware('permission:settings.users.delete')->group(function () {
            // Registered before the wildcard so "bulk" is not read as an id.
            Route::delete('users/bulk', [UserController::class, 'bulkDestroy'])->name('users.bulk-destroy');
            Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
        });

        Route::get('activity', [ActivityLogController::class, 'index'])
            ->middleware('permission:settings.activity_logs.view')
            ->name('activity.index');

        Route::get('activity/export', [ActivityLogController::class, 'export'])
            ->middleware('permission:settings.activity_logs.export')
            ->name('activity.export');

        Route::delete('activity', [ActivityLogController::class, 'destroy'])
            ->middleware('permission:settings.activity_logs.delete')
            ->name('activity.destroy');

        /*
        | The landing page's content (§19).
        |
        | Five lists that all behave identically - see
        | Admin\LandingContentController - so they are registered from one
        | table rather than five near-identical blocks. Each entry is
        | [segment, controller, permission-suffix, has-image].
        |
        | `{id}` is a plain integer rather than a bound model: the shared
        | controller does not know which class it is resolving, so it looks the
        | row up itself.
        */
        $landing = [
            ['testimonials', TestimonialController::class, 'testimonials', true],
            ['outlet-types', OutletTypeController::class, 'outlet_types', false],
            ['integrations', IntegrationController::class, 'integrations', true],
            ['showcases', ShowcaseController::class, 'showcases', true],
            ['landing-stats', LandingStatController::class, 'stats', false],
        ];

        foreach ($landing as [$segment, $controller, $perm, $hasImage]) {
            Route::prefix($segment)->name($segment.'.')->group(function () use ($controller, $perm, $hasImage) {
                Route::get('/', [$controller, 'index'])
                    ->middleware("permission:content.{$perm}.view")->name('index');

                Route::middleware("permission:content.{$perm}.create")->group(function () use ($controller) {
                    // Before the wildcard, or "create" is read as an id.
                    Route::get('create', [$controller, 'create'])->name('create');
                    Route::post('/', [$controller, 'store'])->name('store');
                });

                Route::middleware("permission:content.{$perm}.edit")->group(function () use ($controller, $hasImage) {
                    Route::get('{id}/edit', [$controller, 'edit'])->whereNumber('id')->name('edit');
                    Route::put('{id}', [$controller, 'update'])->whereNumber('id')->name('update');
                    Route::put('{id}/status', [$controller, 'toggleStatus'])->whereNumber('id')->name('status');

                    if ($hasImage) {
                        Route::delete('{id}/image', [$controller, 'destroyImage'])
                            ->whereNumber('id')->name('image.destroy');
                    }
                });

                Route::delete('{id}', [$controller, 'destroy'])
                    ->whereNumber('id')
                    ->middleware("permission:content.{$perm}.delete")
                    ->name('destroy');
            });
        }

        /*
        | Demo requests (§19).
        |
        | No create route: these arrive from the public form at
        | `demo-request.store`. Nobody types a lead in by hand.
        */
        Route::prefix('demo-requests')->name('demo-requests.')->group(function () {
            Route::middleware('permission:content.demo_requests.view')->group(function () {
                Route::get('/', [AdminDemoRequestController::class, 'index'])->name('index');

                Route::get('export', [AdminDemoRequestController::class, 'export'])
                    ->middleware('permission:content.demo_requests.export')
                    ->name('export');

                Route::get('{demoRequest}', [AdminDemoRequestController::class, 'show'])
                    ->whereNumber('demoRequest')->name('show');
            });

            Route::put('{demoRequest}', [AdminDemoRequestController::class, 'update'])
                ->whereNumber('demoRequest')
                ->middleware('permission:content.demo_requests.edit')
                ->name('update');

            Route::delete('{demoRequest}', [AdminDemoRequestController::class, 'destroy'])
                ->whereNumber('demoRequest')
                ->middleware('permission:content.demo_requests.delete')
                ->name('destroy');
        });

        /*
        | System health (§17).
        |
        | Reading is one right; acting is three more. Retrying a failed job
        | re-runs whatever it was - a payment webhook, a campaign send - so it
        | cannot ride along with "may open the screen", and discarding one
        | destroys the only record of what went wrong.
        */
        Route::prefix('health')->name('health.')->group(function () {
            Route::get('/', [SystemHealthController::class, 'index'])
                ->middleware('permission:settings.health.view')
                ->name('index');

            Route::post('backup', [SystemHealthController::class, 'backup'])
                ->middleware('permission:settings.health.create')
                ->name('backup');

            Route::middleware('permission:settings.health.edit')->group(function () {
                Route::post('jobs/retry', [SystemHealthController::class, 'retry'])->name('jobs.retry');
                Route::post('jobs/retry-all', [SystemHealthController::class, 'retryAll'])->name('jobs.retry-all');
            });

            Route::delete('jobs', [SystemHealthController::class, 'discard'])
                ->middleware('permission:settings.health.delete')
                ->name('jobs.discard');
        });

        /*
        | The support desk (§2).
        |
        | Two audiences on one set of routes - a restaurant raising a ticket
        | and the platform staff answering it. The `view` right is enough to
        | reach every screen here, because the *scope* of what a person sees is
        | decided by SupportTicket::scopeVisibleTo() rather than by the route:
        | without `support.tickets.manage` the same URL returns only their own
        | company's tickets, and a ticket belonging to anybody else 404s.
        |
        | That is deliberate and it is the reason these are not two route
        | groups. Splitting them would have put the tenant boundary in the
        | router, where it would have to be repeated on every action and would
        | be missing from the one somebody adds next year. It lives in the
        | model instead, and the router only asks the cheaper question.
        */
        Route::prefix('support')->name('support.')->group(function () {
            Route::middleware('permission:support.tickets.view')->group(function () {
                Route::get('/', [SupportTicketController::class, 'index'])->name('index');

                // Before the wildcard, or "export" is read as a ticket id.
                Route::get('export', [SupportTicketController::class, 'export'])
                    ->middleware('permission:support.tickets.export')
                    ->name('export');

                Route::get('create', [SupportTicketController::class, 'create'])
                    ->middleware('permission:support.tickets.create')
                    ->name('create');

                Route::get('{ticket}', [SupportTicketController::class, 'show'])
                    ->whereNumber('ticket')
                    ->name('show');
            });

            Route::post('/', [SupportTicketController::class, 'store'])
                ->middleware('permission:support.tickets.create')
                ->name('store');

            /*
            | Replying needs `view`, not `edit`.
            |
            | A restaurant that may read its own ticket must be able to answer
            | the question the desk asked it; requiring `edit` would mean the
            | owner granting their manager the right to reassign and reprioritise
            | just so they could say "yes, Tuesday works".
            |
            | Whether the reply is posted as support, and whether an internal
            | note is allowed at all, is decided in the controller from
            | `manage` - never from the form.
            */
            Route::post('{ticket}/replies', [SupportTicketController::class, 'reply'])
                ->whereNumber('ticket')
                ->middleware('permission:support.tickets.view')
                ->name('reply');

            Route::middleware('permission:support.tickets.edit')->group(function () {
                Route::put('{ticket}/resolve', [SupportTicketController::class, 'resolve'])
                    ->whereNumber('ticket')->name('resolve');
                Route::put('{ticket}/close', [SupportTicketController::class, 'close'])
                    ->whereNumber('ticket')->name('close');
                Route::put('{ticket}/reopen', [SupportTicketController::class, 'reopen'])
                    ->whereNumber('ticket')->name('reopen');
            });

            /*
            | Desk-only. Gated twice on purpose: the route asks for `manage`,
            | and the controller asks again - because these two are the actions
            | whose whole point is that a customer cannot perform them, and a
            | route file is an easier thing to edit by accident than a guard
            | sitting next to the code it protects.
            */
            Route::middleware('permission:support.tickets.manage')->group(function () {
                Route::put('{ticket}/assign', [SupportTicketController::class, 'assign'])
                    ->whereNumber('ticket')->name('assign');
                Route::put('{ticket}/priority', [SupportTicketController::class, 'priority'])
                    ->whereNumber('ticket')->name('priority');
            });

            Route::delete('{ticket}', [SupportTicketController::class, 'destroy'])
                ->whereNumber('ticket')
                ->middleware('permission:support.tickets.delete')
                ->name('destroy');
        });
    });
});
