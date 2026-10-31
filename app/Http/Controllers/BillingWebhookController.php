<?php

namespace App\Http\Controllers;

use App\Services\SubscriptionBillingService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * Where the provider tells us a subscription payment really happened.
 *
 * Same contract as PaymentWebhookController: signature first, always answer
 * 200 so the provider does not retry for days. A failure while applying is
 * reported and still acknowledged; the signed event can be replayed from the
 * provider's dashboard and capture() is idempotent.
 */
class BillingWebhookController extends Controller
{
    public function __construct(private readonly SubscriptionBillingService $billing) {}

    public function __invoke(Request $request): Response
    {
        try {
            $this->billing->confirmFromWebhook($request->getContent(), $request->headers->all());
        } catch (Throwable $e) {
            report($e);
        }

        return response('', 200);
    }
}
