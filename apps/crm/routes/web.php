<?php

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\AlertController;
use App\Http\Controllers\Admin\ApiTokenController;
use App\Http\Controllers\Admin\BarcodeLabelController;
use App\Http\Controllers\Admin\BrandController;
use App\Http\Controllers\Admin\CampaignController;
use App\Http\Controllers\Admin\CashRegisterController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\CompanySettingController;
use App\Http\Controllers\Admin\CouponController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\CustomerLedgerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DemoRequestController as AdminDemoRequestController;
use App\Http\Controllers\Admin\EmailLogController;
use App\Http\Controllers\Admin\EmailTemplateController;
use App\Http\Controllers\Admin\ErpPanelController;
use App\Http\Controllers\Admin\FaqController;
use App\Http\Controllers\Admin\FeedbackController;
use App\Http\Controllers\Admin\FloorController;
use App\Http\Controllers\Admin\IntegrationController;
use App\Http\Controllers\Admin\InvoiceController;
use App\Http\Controllers\Admin\KitchenController;
use App\Http\Controllers\Admin\KitchenStationController;
use App\Http\Controllers\Admin\LandingStatController;
use App\Http\Controllers\Admin\LiveOrderController;
use App\Http\Controllers\Admin\LoginController;
use App\Http\Controllers\Admin\LoyaltyController;
use App\Http\Controllers\Admin\ModifierController;
use App\Http\Controllers\Admin\OnlineOrderController;
use App\Http\Controllers\Admin\OnlinePaymentController;
use App\Http\Controllers\Admin\OutletTypeController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\PaymentReminderController;
use App\Http\Controllers\Admin\PosController;
use App\Http\Controllers\Admin\PriceListController;
use App\Http\Controllers\Admin\PrinterController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\ReservationController;
use App\Http\Controllers\Admin\RestaurantTableController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SalesReturnController;
use App\Http\Controllers\Admin\ScannerController;
use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\Admin\ShopController;
use App\Http\Controllers\Admin\ShopSwitchController;
use App\Http\Controllers\Admin\ShowcaseController;
use App\Http\Controllers\Admin\SliderController;
use App\Http\Controllers\Admin\SupportTicketController;
use App\Http\Controllers\Admin\SystemHealthController;
use App\Http\Controllers\Admin\TableBillController;
use App\Http\Controllers\Admin\TableQrController;
use App\Http\Controllers\Admin\TaxRateController;
use App\Http\Controllers\Admin\TenantSwitchController;
use App\Http\Controllers\Admin\TestimonialController;
use App\Http\Controllers\DemoRequestController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\SeoController;
use App\Http\Controllers\TableFeedbackController;
use App\Http\Controllers\TableManifestController;
use App\Http\Controllers\TableOrderController;
use App\Http\Controllers\TablePaymentController;
use App\Http\Controllers\TableScanController;
use App\Http\Controllers\TableVerifyController;
use App\Support\Accounts;
use Illuminate\Support\Facades\Route;

/*
| The public landing page.
|
| Was Laravel's stock welcome view. It is now a rendering of the admin panel's
| Content section - sliders, services, the menu and FAQs - with the name,
| logo, address, hours and social links coming from Company Settings.
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
| What a crawler is told (SS19).
|
| Generated rather than files in public/, because a static sitemap is a list
| somebody has to remember to update - and the first thing it does on a real
| install is go stale. robots.txt keeps the back office, the guest's table
| session and the signup redirect out of the index; the sitemap lists the
| marketing page, each live storefront and the blog. See SeoController.
*/
Route::get('robots.txt', [SeoController::class, 'robots'])->name('robots');
Route::get('sitemap.xml', [SeoController::class, 'sitemap'])->name('sitemap');

// The Partner app's test APK, uploaded by the build-partner-app workflow; 404 until the first build lands.
Route::get('download/partner-app', function () {
    $apk = storage_path('app/downloads/restro-one-partner.apk');
    abort_unless(is_file($apk), 404);

    return response()->download($apk, 'restro-one-partner.apk', ['Content-Type' => 'application/vnd.android.package-archive']);
})->name('partner-app.download');

/*
| Opening an account is Khana Accounts' job now (§21).
|
| Signing up makes a business, its first outlet, its owner and the products
| that outlet buys - four records Accounts owns - so this path only forwards.
| It stays because printed links and bookmarks still point here, and
| `/signup/restaurant` from an old price card should land somewhere useful
| rather than on a 404. The plan slug is ignored: Accounts shows its own.
|
| With no Accounts address configured there is nowhere to send anybody, so it
| goes back to the landing page, where the demo form is.
*/
Route::get('signup/{plan?}', fn () => redirect()->away(Accounts::url('/signup') ?? route('landing')))
    ->where('plan', '[a-z0-9]+(?:-[a-z0-9]+)*')
    ->name('signup');

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
|
| `guest.entitled` on every route that shows the menu or takes an order: an
| outlet that has not got CRM - suspended, ended, never bought - takes no
| orders from its stickers. See EnsureGuestOutletIsEntitled for the few that
| are left open, and why.
*/
Route::get('t/{token}', [TableScanController::class, 'scan'])
    ->where('token', '[A-Za-z0-9]{32}')
    ->middleware(['throttle:30,1', 'guest.entitled'])
    ->name('table.scan');

/*
| The page the guest sits on. Takes no parameter: the session lives in their
| own cookie, so a screenshot of this URL in a group chat is worth nothing.
*/
Route::get('t', [TableScanController::class, 'show'])->middleware('guest.entitled')->name('table.show');

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
Route::get('t/cart', [TableOrderController::class, 'cart'])->middleware('guest.entitled')->name('table.cart');
Route::get('t/orders', [TableOrderController::class, 'orders'])->middleware('guest.entitled')->name('table.orders');

Route::middleware(['throttle:60,1', 'guest.entitled'])->group(function () {
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
    ->middleware(['throttle:10,1', 'guest.entitled'])
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
    ->middleware(['throttle:5,1', 'guest.entitled'])
    ->name('table.verify.send');

Route::post('t/verify', [TableVerifyController::class, 'confirm'])
    ->middleware(['throttle:20,1', 'guest.entitled'])
    ->name('table.verify.confirm');

Route::get('t/verify', [TableVerifyController::class, 'show'])->middleware('guest.entitled')->name('table.verify');

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
Route::middleware(['throttle:10,1', 'guest.entitled'])->group(function () {
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
        | "I have forgotten my password" - answered by Khana Accounts.
        |
        | Passwords live there now, so CRM neither resets nor sets one. The
        | address stays as a forward for bookmarks and for sign-in pages that
        | still link to it; with no Accounts address configured it goes back
        | to the sign-in page.
        */
        Route::get('password/forgot', fn () => redirect()->away(Accounts::url('/forgot') ?? route('admin.login')))
            ->name('password.request');
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
    |   shop.entitled  has THIS outlet bought CRM? Accounts sells per outlet
    |                  and says so through the kit's Entitlements, so a lapsed
    |                  branch is stopped (or read-only, when suspended) while
    |                  the group's others keep trading - and nobody is signed
    |                  out.
    |   module         does this branch run the line of business this route
    |                  belongs to? Derived from the route's own `permission:`
    |                  middleware, so it cannot drift from it.
    |
    | The permission itself is checked per route, below.
    */
    Route::middleware(['auth', 'admin', 'tenant.active', 'shop.entitled', 'module'])->group(function () {
        Route::get('/', fn () => redirect()->route('admin.dashboard'));
        Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

        Route::get('dashboard', DashboardController::class)
            ->middleware('permission:dashboard.overview.view')
            ->name('dashboard');

        /*
        | The day's food cost from ERP, for the home screen's tile. A fragment
        | the dashboard asks for once it has drawn. Gated on the profit report
        | - so the `module` gate and the permission together are the same pair
        | of checks the profit tile is drawn behind - and a 404 for an outlet
        | not linked to ERP. See ErpPanelController.
        */
        Route::get('dashboard/food-cost', [ErpPanelController::class, 'foodCost'])
            ->middleware('permission:reports.profit_report.view')
            ->name('dashboard.food-cost');

        /*
        | Own-account screen. Ungated on purpose - it only ever reads and
        | writes $request->user(), so every admin may reach their own.
        |
        | Only what is CRM's own is edited here: the picture, and which
        | devices are signed in to CRM. Name, email and password belong to
        | Khana Accounts and are changed there.
        */
        Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::post('profile/avatar', [ProfileController::class, 'updateAvatar'])
            ->name('profile.avatar');
        Route::delete('profile/avatar', [ProfileController::class, 'destroyAvatar'])
            ->name('profile.avatar.destroy');
        Route::delete('profile/sessions/{session}', [ProfileController::class, 'destroySession'])
            ->whereNumber('session')
            ->name('profile.sessions.destroy');
        Route::delete('profile/sessions', [ProfileController::class, 'destroyOtherSessions'])
            ->name('profile.sessions.destroy-others');

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
            /*
            | Stock and cost for one dish, from ERP. A fragment the item's
            | page asks for after it has drawn, for whoever may see that
            | page - it already prints the dish's cost. A 404 for an outlet
            | not linked to ERP. See ErpPanelController.
            */
            Route::get('products/{product}/erp-stock', [ErpPanelController::class, 'stock'])
                ->whereNumber('product')
                ->name('products.erp-stock');
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

        // Filed under Finance rather than the menu: a GST slab is an
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

            /*
            | Cash out of the drawer.
            |
            | Rides on `create`, the right that opens the register, rather
            | than on `edit`, which closes it. Paying the gas man is a
            | cashier's job, and the Cashier preset holds `create` and not
            | `edit` - so the closing right would have turned away the one
            | person standing at the drawer. Every preset that may close a
            | register may open one too, so nobody who could have paid out
            | under `edit` is left out by this.
            |
            | The bill list is the same right: it exists only to be picked
            | from on that card. It is a fragment the card asks for after the
            | page has drawn, and the one thing here that reads ERP - see
            | ErpPanelController.
            */
            Route::middleware('permission:pos.registers.create')->group(function () {
                Route::post('{register}/paid-outs', [CashRegisterController::class, 'payOut'])
                    ->whereNumber('register')
                    ->name('paid-outs.store');
                Route::get('{register}/supplier-bills', [ErpPanelController::class, 'bills'])
                    ->whereNumber('register')
                    ->name('supplier-bills');
            });

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
