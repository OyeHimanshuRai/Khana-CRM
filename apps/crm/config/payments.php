<?php

/*
|--------------------------------------------------------------------------
| Online payment (§11)
|--------------------------------------------------------------------------
|
| A guest paying from their phone, and a customer paying for a web order.
|
| Every gateway is behind App\Contracts\PaymentGateway, and the one in use is
| named here. That is not architecture for its own sake: an Indian restaurant
| changes payment provider roughly as often as it changes bank, and the cost of
| the abstraction is one interface with five methods against the cost of
| finding every place a provider's name was hard-coded.
|
| ---------------------------------------------------------------------------
| No keys means no online payment, and that is a working state
| ---------------------------------------------------------------------------
|
| A shop that has not set its keys up gets a guest page that says "pay at the
| counter" and no Pay button. Nothing is stubbed, nothing throws, and the
| restaurant works exactly as it did before - which is what most of them do for
| the first month.
|
| ---------------------------------------------------------------------------
| The webhook is the truth
| ---------------------------------------------------------------------------
|
| A browser that says "paid" is a browser, and a guest's phone dies, loses
| signal or is closed between paying and telling us. §11 asks for "webhook-based
| payment verification" for exactly that reason: the callback from the browser
| is a fast path, the webhook is the record, and both converge on one
| idempotent capture.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Which gateway
    |--------------------------------------------------------------------------
    |
    | `offline` is the default and means there is no online payment: everybody
    | pays at the counter. It is a real gateway object rather than a null
    | check scattered through the code, so every caller has something to talk
    | to and nothing has to ask "is payment on" before it can render a page.
    |
    */

    'gateway' => env('PAYMENT_GATEWAY', 'offline'),

    /*
    |--------------------------------------------------------------------------
    | Gateways
    |--------------------------------------------------------------------------
    |
    | `label` is what a guest sees on the button. `currency` is what the
    | provider is told; every one of these works in paise, which is why the
    | amounts are multiplied by 100 on the way out - see the gateway.
    |
    */

    'gateways' => [

        'offline' => [
            'driver' => 'offline',
            'label' => 'Pay at the counter',
        ],

        /*
         | Razorpay. UPI intent, cards and netbanking arrive through one
         | integration, which is why it is the one wired first for a
         | QR-ordering restaurant in India.
         */
        'razorpay' => [
            'driver' => 'razorpay',
            'label' => 'Pay now',
            'key' => env('RAZORPAY_KEY'),
            'secret' => env('RAZORPAY_SECRET'),
            // A different secret from the API one. Razorpay signs the webhook
            // body with this, and using the API secret to check it is the
            // commonest way a webhook silently never verifies.
            'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
            'currency' => 'INR',
            'checkout_js' => 'https://checkout.razorpay.com/v1/checkout.js',
            'api' => 'https://api.razorpay.com/v1',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | How long an unpaid intent stays open
    |--------------------------------------------------------------------------
    |
    | After this a guest who abandoned the checkout gets a fresh intent rather
    | than being handed back a stale provider order. Long enough to survive
    | somebody fetching their card from a coat.
    |
    */

    'intent_ttl_minutes' => 30,

];
