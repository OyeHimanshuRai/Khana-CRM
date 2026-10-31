<?php

namespace App\Http\Controllers;

use App\Models\DemoRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
 * With JavaScript, demo-form.js checks the fields and app.js posts the form
 * without a reload. Without it, a plain POST and a redirect back - a lead
 * form that silently needs a script is a lead form that silently loses leads.
 *
 * Every lead also rings the platform's bell (EventNotifier::demoRequested).
 */
class DemoRequestController extends Controller
{
    /** The hidden field. Named like something a bot would want to fill. */
    private const HONEYPOT = 'website';

    /**
     * Posted by demo-form.js (JSON, no reload) or as a plain form.
     *
     * The same validation either way. For the JSON case Laravel answers a
     * failure with a 422 and the errors bag, which app.js paints onto the
     * fields; success is a thank-you toast and an emptied form.
     */
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
            return $this->thanks($request);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],

            /*
             | Either, not both, and at least one. A form that insists on an
             | email address loses the restaurant owner who only has a phone,
             | and that is the person most likely to buy.
             */
            'email' => ['nullable', 'required_without:phone', 'email', 'max:150'],
            'phone' => ['nullable', 'required_without:email', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{7,30}$/'],

            'city' => ['nullable', 'string', 'max:90'],
            'business_name' => ['nullable', 'string', 'max:150'],
            'outlets' => ['nullable', 'integer', 'between:1,65535'],
            'message' => ['nullable', 'string', 'max:2000'],
        ], [
            'email.required_without' => 'Give us an email address or a phone number.',
            'phone.required_without' => 'Give us a phone number or an email address.',
            'phone.regex' => 'Enter a valid phone number.',
        ]);

        $lead = DemoRequest::create([
            ...$data,
            // Lower-cased, so the inbox does not list the same lead twice.
            'email' => isset($data['email']) ? Str::lower(trim($data['email'])) : null,
            'status' => DemoRequest::NEW,
            'ip_address' => $request->ip(),
        ]);

        app(\App\Services\EventNotifier::class)->demoRequested($lead);

        return $this->thanks($request);
    }

    /**
     * Back to the form, with a thank-you.
     *
     * A named route, not `url()->previous()`. That helper reads the Referer
     * header, which the person posting the form controls - so it would turn
     * this into an open redirect: a link that posts the form with a Referer of
     * someone else's site and lands the visitor there, on our domain's say-so.
     * There is exactly one page this form lives on - /demo - so name it.
     */
    private function thanks(Request $request): RedirectResponse|JsonResponse
    {
        $message = 'Thank you — we will call you back shortly.';

        if ($request->expectsJson()) {
            return json_success($message);
        }

        return redirect()
            ->route('demo')
            ->with('demo_status', $message);
    }
}
