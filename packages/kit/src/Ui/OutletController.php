<?php

namespace Khana\Kit\Ui;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Khana\Kit\Support\ApiResponse;
use Khana\Kit\Tenancy\Context;

/**
 * Choosing the outlet, in CRM and ERP.
 *
 *   GET  /khana/open?outlet=<id>  where the product switcher lands. Signed in:
 *                                 select that outlet and go home. Not signed
 *                                 in: remember it, sign in through Accounts,
 *                                 and select it on the way back.
 *   POST /khana/outlet            the outlet switcher in the top bar.
 *
 * Both ask Context, which refuses an outlet the person may not reach. A
 * refused id is dropped silently: the person lands on the outlet they had,
 * which is the same page a stale link would show anyway.
 */
final class OutletController
{
    public const PENDING = 'khana.sso.outlet';

    public function open(Request $request, Context $context): RedirectResponse
    {
        $outlet = $request->query('outlet');
        $outlet = is_string($outlet) && $outlet !== '' ? $outlet : null;

        if (Auth::check()) {
            if ($outlet !== null) {
                $context->setOutlet($outlet);
            }

            return redirect()->to(self::home());
        }

        if ($outlet !== null) {
            $request->session()->put(self::PENDING, $outlet);
        }

        return redirect()->to(Route::has('khana.sso.login') ? route('khana.sso.login') : self::home());
    }

    public function switch(Request $request, Context $context): RedirectResponse|JsonResponse
    {
        $outlet = $request->input('outlet');

        $switched = $context->setOutlet($outlet === 'all' || $outlet === null || $outlet === '' ? null : (string) $outlet);

        // The page behind the switcher was drawn for the old outlet, so the
        // answer is always a reload of it.
        $back = url()->previous(self::home());

        if ($request->expectsJson()) {
            return $switched
                ? ApiResponse::success('Switched outlet.', [], $back)
                : ApiResponse::error('You do not have access to that outlet.', [], 403);
        }

        return $switched
            ? redirect()->to($back)
            : redirect()->to($back)->with('error', 'You do not have access to that outlet.');
    }

    /** Apply an outlet remembered before sign-in, once someone is signed in. */
    public static function applyPending(Request $request, Context $context): void
    {
        $outlet = $request->session()->pull(self::PENDING);

        if (is_string($outlet) && Auth::check()) {
            $context->setOutlet($outlet);
        }
    }

    private static function home(): string
    {
        $home = config('khana.ui.home');

        return is_string($home) && Route::has($home) ? route($home) : url('/');
    }
}
