<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\TableSession;
use App\Models\VerificationCode;
use App\Services\OtpService;
use App\Services\TableSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * The optional "prove it is your phone" step (§3.8).
 *
 * ---------------------------------------------------------------------------
 * Why this exists at all
 * ---------------------------------------------------------------------------
 *
 * Not security - see OtpService. It is against prank orders: somebody who
 * walks past the window, scans a code and sends forty biryanis to a table
 * they are not sitting at. A branch that has had that happen turns it on.
 *
 * ---------------------------------------------------------------------------
 * The cart survives
 * ---------------------------------------------------------------------------
 *
 * A guest who is bounced here has already chosen everything they want. The
 * cart is untouched by all of this: verifying sends them back to it, and
 * abandoning the step leaves it exactly where it was. Making somebody
 * re-choose a meal because a text was slow would lose the order the feature
 * was meant to protect.
 *
 * Unauthenticated, like the rest of the guest journey. The sitting comes from
 * the guest's own cookie and never from the request - a session id in a form
 * field would let anybody verify onto somebody else's table.
 */
class TableVerifyController extends Controller
{
    public function __construct(
        private readonly TableSessionService $sessions,
        private readonly OtpService $otp,
    ) {}

    /** The screen: ask for a number, or ask for the code just sent. */
    public function show(Request $request): View|RedirectResponse
    {
        $session = $this->session($request);

        if ($session === null) {
            return redirect()->route('table.expired');
        }

        if ($session->mobile_verified_at !== null) {
            return redirect()->route('table.cart');
        }

        return view('table.verify', $this->screen($request, $session));
    }

    /** Send a code to the number the guest typed in. */
    public function send(Request $request): RedirectResponse
    {
        $session = $this->session($request);

        if ($session === null) {
            return redirect()->route('table.expired');
        }

        $data = $request->validate([
            'mobile' => ['required', 'string', 'max:30'],
        ]);

        try {
            $code = $this->otp->send(
                mobile: $data['mobile'],
                purpose: VerificationCode::TABLE_SESSION,
                verifiable: $session,
                shopName: $session->table?->shop?->name,
                ip: $request->ip(),
            );
        } catch (RuntimeException $e) {
            /*
             | The number is kept in the session so the form comes back
             | filled in. Somebody who has just been told to wait sixty
             | seconds should not also have to retype their phone number.
             */
            return back()
                ->with('verify_mobile', $this->otp->normalise($data['mobile']))
                ->with('table_error', $e->getMessage());
        }

        return redirect()
            ->route('table.verify')
            ->with('verify_mobile', $code->destination)
            ->with('table_notice', 'We sent a code to '.$code->maskedDestination().'.');
    }

    /** Check the code and let the guest back to their cart. */
    public function confirm(Request $request): RedirectResponse
    {
        $session = $this->session($request);

        if ($session === null) {
            return redirect()->route('table.expired');
        }

        $data = $request->validate([
            'mobile' => ['required', 'string', 'max:30'],
            'code' => ['required', 'string', 'max:10'],
        ]);

        try {
            $this->otp->verify($data['mobile'], $data['code'], VerificationCode::TABLE_SESSION);
        } catch (RuntimeException $e) {
            return back()
                ->with('verify_mobile', $this->otp->normalise($data['mobile']))
                ->with('table_error', $e->getMessage());
        }

        $this->otp->confirmSession($session, $data['mobile']);

        /*
         | Back to the cart rather than straight to placing the order.
         |
         | Deliberate: the guest last saw their cart, and landing on "your
         | order is with the kitchen" after typing a code would mean an order
         | went in on a screen they never confirmed. One more tap is cheap;
         | an order nobody meant to send is not.
         */
        return redirect()
            ->route('table.cart')
            ->with('table_notice', 'Thanks — your number is confirmed. You can send your order now.');
    }

    /** @return array<string, mixed> */
    private function screen(Request $request, TableSession $session): array
    {
        $mobile = (string) ($request->session()->get('verify_mobile') ?? $session->guest_mobile ?? '');

        $pending = $mobile === '' ? null : VerificationCode::query()
            ->where('destination', $this->otp->normalise($mobile))
            ->where('purpose', VerificationCode::TABLE_SESSION)
            ->live()
            ->latest('id')
            ->first();

        return [
            'session' => $session,
            'table' => $session->table,
            'shop' => $session->table?->shop,
            'company' => Setting::get('company_name', config('app.name')),
            'mobile' => $mobile,
            'pending' => $pending,
            'wait' => $pending?->resendWaitSeconds() ?? 0,
        ];
    }

    /**
     * The sitting, from the guest's own cookie.
     *
     * Same resolution as TableOrderController, and never from the request
     * body - a session token in a form field would let anybody verify onto
     * somebody else's table.
     */
    private function session(Request $request): ?TableSession
    {
        return $this->sessions->resolve(
            $request->session()->get(TableScanController::SESSION_KEY)
        );
    }
}
