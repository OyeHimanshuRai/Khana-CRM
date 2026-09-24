<?php

namespace App\Http\Controllers;

use App\Models\DemoRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The public "book a demo" form (§19).
 *
 * ---------------------------------------------------------------------------
 * The only public write on this site that is not behind a table QR
 * ---------------------------------------------------------------------------
 *
 * Which makes it the thing bots find. Three defences, in order of how much
 * they cost the honest visitor:
 *
 *   1. A honeypot field, hidden with CSS. Costs a real person nothing, catches
 *      the large majority of automated posts, and - unlike a CAPTCHA - does
 *      not punish somebody filling a form in on a phone in a noisy kitchen.
 *   2. Rate limiting on the route, so a script that ignores the honeypot
 *      cannot leave a thousand rows overnight.
 *   3. The IP on the row, so a flood that gets through can be recognised for
 *      what it is afterwards.
 *
 * Deliberately no CAPTCHA. This form is how the business gets paid; every
 * point of friction on it is revenue, and the failure it prevents - a few junk
 * rows in an inbox somebody reads anyway - is cheap by comparison.
 *
 * ---------------------------------------------------------------------------
 * It works without JavaScript
 * ---------------------------------------------------------------------------
 *
 * A plain POST and a redirect back, like the guest's table pages. Where a
 * script is available the page upgrades itself - the same post goes through
 * fetch and the answer is a toast, so nothing the visitor typed is lost to a
 * reload - but the enquiry is written the same way either way. A lead form
 * that silently needs a script is a lead form that silently loses leads.
 */
class DemoRequestController extends Controller
{
    /** The hidden field. Named like something a bot would want to fill. */
    private const HONEYPOT = 'website';

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        /*
         | Caught in the honeypot: accept it, store nothing, and say thank you.
         |
         | Telling a bot it failed only teaches whoever wrote it to try again
         | with the field left blank. A human who somehow trips this - an
         | aggressive password manager filling every field it sees - gets a
         | confirmation rather than an error they cannot act on, which is the
         | lesser harm of the two.
         */
        if (filled($request->input(self::HONEYPOT))) {
            return $this->thanks();
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],

            /*
             | Either, not both, and at least one. A form that insists on an
             | email address loses the restaurant owner who only has a phone,
             | and that is the person most likely to buy.
             */
            'email' => ['nullable', 'required_without:phone', 'email', 'max:150'],
            'phone' => ['nullable', 'required_without:email', 'string', 'max:30'],

            'city' => ['nullable', 'string', 'max:90'],
            'business_name' => ['nullable', 'string', 'max:150'],
            'outlets' => ['nullable', 'integer', 'between:1,65535'],
            'message' => ['nullable', 'string', 'max:2000'],
        ], [
            'email.required_without' => 'Give us an email address or a phone number.',
            'phone.required_without' => 'Give us a phone number or an email address.',
        ]);

        DemoRequest::create([
            ...$data,
            'status' => DemoRequest::NEW,
            'ip_address' => $request->ip(),
        ]);

        return $this->thanks();
    }

    /**
     * Back to the form, with a thank-you.
     *
     * A named route, not `url()->previous()`. That helper reads the Referer
     * header, which the person posting the form controls - so it would turn
     * this into an open redirect: a link that posts the form with a Referer of
     * someone else's site and lands the visitor there, on our domain's say-so.
     * There is exactly one page this form lives on, so name it.
     */
    private function thanks(): RedirectResponse|JsonResponse
    {
        $message = 'Thank you — we will call you back shortly.';

        /*
         | With a script on the page the answer is a toast, so the enquiry the
         | visitor just typed does not vanish under a page reload and they are
         | not scrolled back to the top to find out whether it worked.
         |
         | Without one this is unchanged: the browser posts, we redirect back
         | to the section they were reading, and the flash says the same
         | sentence. Neither path is the "real" one.
         */
        if (request()->expectsJson()) {
            return json_success($message);
        }

        return redirect()
            ->to(route('landing').'#demo')
            ->with('demo_status', $message);
    }
}
