<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\TableQr;
use App\Services\MenuService;
use App\Services\TableQrService;
use App\Services\TableSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * What a phone lands on when it scans a table sticker (§3.3 - §3.7).
 *
 * The only unauthenticated route in the app that opens a record. Three things
 * follow from that and shape everything here:
 *
 *   1. There is no tenant in context. Nobody is signed in, so the token has
 *      to identify the branch by itself - which is why table QR tokens are
 *      globally unique and why every read here goes through allShops().
 *
 *   2. A withdrawn code must be tellable from one that never existed. A
 *      sticker outlives the row it came from, so a revoked token gets an
 *      explanation and a made-up one gets a 404. Telling a guest "no such
 *      table" when the real answer is "we reprinted these last week" sends
 *      them to complain about the wrong thing.
 *
 *   3. It is rate limited. It is reachable by anyone with a camera, and it
 *      writes - a scan opens a session. See the route.
 *
 * The session token goes in the guest's own session cookie rather than the
 * URL. A URL with a session token in it gets screenshotted into a group chat,
 * and whoever opens it is then ordering onto somebody else's bill.
 */
class TableScanController extends Controller
{
    /** Where the guest's handle on their sitting is kept. */
    public const SESSION_KEY = 'table_session_token';

    public function __construct(
        private readonly TableSessionService $sessions,
        private readonly TableQrService $qrs,
        private readonly MenuService $menu,
    ) {}

    /**
     * Resolve a scanned token and seat the party.
     *
     * Redirects rather than rendering, so the address bar loses the QR token
     * as soon as it has been used. The sticker's URL is public and printed;
     * the page the guest actually sits on should not be.
     */
    public function scan(Request $request, string $token): RedirectResponse
    {
        /** @var TableQr|null $qr */
        $qr = TableQr::allShops()
            ->where('token', $token)
            ->with(['table.floor', 'table.shop'])
            ->first();

        if ($qr === null) {
            abort(404);
        }

        if (! $qr->isLive()) {
            return redirect()->route('table.retired');
        }

        $table = $qr->table;

        /*
         | A table out of service, or one whose dining area is closed, is not
         | orderable however good the sticker is. The rooftop shuts in the
         | monsoon and its stickers stay on the tables.
         */
        if ($table === null || ! $table->is_active || ! ($table->floor?->is_active ?? false)) {
            return redirect()->route('table.closed');
        }

        $shop = $table->shop;

        if ($shop === null || ! $shop->is_active) {
            return redirect()->route('table.closed');
        }

        $session = $this->sessions->openFor($table, $qr);

        $this->qrs->recordScan($qr);

        /*
         | Regenerated per scan, because a session cookie that outlived the
         | sitting would put the next guest at this table onto the last
         | party's bill. Laravel keeps the flash data across the regenerate.
         */
        $request->session()->put(self::SESSION_KEY, $session->token);

        return redirect()->route('table.show');
    }

    /**
     * The menu, at the table the guest is sitting at (§14).
     *
     * Reads the session from the cookie rather than the URL, so this route
     * takes no parameters and a shared link is worth nothing.
     *
     * The card is built for the *branch the table belongs to*, not for any
     * shop in context - there is no signed-in user here and no tenant scope,
     * so the session is the only thing that says which kitchen is cooking.
     *
     * Sold-out and out-of-window dishes are rendered and marked rather than
     * dropped: see MenuService::card().
     */
    public function show(Request $request): View|RedirectResponse
    {
        $session = $this->sessions->resolve($request->session()->get(self::SESSION_KEY));

        if ($session === null) {
            // Their sitting ended, or the cookie is stale. Sending them back
            // to the sticker is the only recovery that does not need staff.
            $request->session()->forget(self::SESSION_KEY);

            return redirect()->route('table.expired');
        }

        $session->forceFill(['last_activity_at' => now()])->save();

        $shop = $session->table?->shop;

        return view('table.menu', [
            'session' => $session,
            'table' => $session->table,
            'floor' => $session->table?->floor,
            'shop' => $shop,
            'company' => Setting::get('company_name', config('app.name')),
            'sections' => $shop ? $this->menu->card($shop) : collect(),
            'menu' => $this->menu,
        ]);
    }

    /* ------------------------------------------------------- dead ends */

    /**
     * The three ways a scan can fail that are not a 404.
     *
     * Separate routes rather than one page with a query string, so a guest
     * who reloads gets the same message and none of them can be reached by
     * guessing at a parameter.
     */
    public function retired(): View
    {
        return view('table.notice', [
            'heading' => 'This code has been replaced',
            'body' => 'The sticker on your table is out of date. Please ask a member of staff '
                .'for the current code — they will have it in seconds.',
            'company' => Setting::get('company_name', config('app.name')),
        ]);
    }

    public function closed(): View
    {
        return view('table.notice', [
            'heading' => 'This table is not taking orders',
            'body' => 'This area may be closed at the moment. Please ask a member of staff, '
                .'who can seat you and take your order.',
            'company' => Setting::get('company_name', config('app.name')),
        ]);
    }

    public function expired(): View
    {
        return view('table.notice', [
            'heading' => 'Your session has ended',
            'body' => 'Scan the QR code on your table again to start a new one.',
            'company' => Setting::get('company_name', config('app.name')),
        ]);
    }
}
