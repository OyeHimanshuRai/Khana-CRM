<?php

namespace App\Http\Controllers;

use App\Services\PaymentIntentService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Where the provider tells us what actually happened (§11).
 *
 * ---------------------------------------------------------------------------
 * This endpoint always answers 200
 * ---------------------------------------------------------------------------
 *
 * Every provider retries a non-2xx for hours or days. An endpoint that
 * returned 500 on a webhook it did not recognise would spend a week being
 * hammered with the same event, and the genuine ones would queue behind it.
 *
 * So: a bad signature, an event this system ignores, an intent we cannot find
 * - all of them are read, discarded and acknowledged. The service returns null
 * and the log carries anything worth a person's attention.
 *
 * ---------------------------------------------------------------------------
 * No session, no CSRF, no shop
 * ---------------------------------------------------------------------------
 *
 * A webhook arrives from a server, not a browser. It is excluded from CSRF in
 * bootstrap/app.php, it has no authenticated user, and it must not need a
 * chosen branch - which is why the lookup goes through allShops() and the
 * intent tells us which shop it belongs to.
 *
 * The *only* thing standing between this URL and anybody on the internet is
 * the signature, which is why it is checked before anything else is read.
 */
class PaymentWebhookController extends Controller
{
    public function __construct(private readonly PaymentIntentService $payments) {}

    /**
     * Somebody has pasted the URL into a browser to check it is there.
     *
     * A 405 exception page is a frightening answer to a reasonable question,
     * and "is this URL live" is the first thing anybody does after copying it
     * into a provider's dashboard. So GET answers in words.
     *
     * Deliberately worded so it cannot be mistaken for a successful webhook
     * test: it confirms the route exists and nothing else. It touches no data
     * and changes no state - there is nothing here to protect.
     */
    public function show(): Response
    {
        $lines = [
            'This is a payment webhook endpoint.',
            '',
            'It only accepts POST, from your payment provider, signed with your webhook secret.',
            'Seeing this page means the URL is reachable. It does not mean a webhook has been received.',
            '',
            'Set it in Razorpay: Settings > Webhooks, on the payment.captured event.',
        ];

        return response(
            implode(PHP_EOL, $lines).PHP_EOL,
            200,
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }

    public function __invoke(Request $request): Response
    {
        $intent = $this->payments->confirmFromWebhook(
            $request->getContent(),
            $request->headers->all(),
        );

        /*
         | The body is deliberately empty and the same either way. A provider
         | does not read it, and a response that said "unknown event" would be
         | a way to probe which events this system acts on.
         */
        return response('', 200);
    }
}
