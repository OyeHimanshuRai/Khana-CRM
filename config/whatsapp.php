<?php

/*
|--------------------------------------------------------------------------
| Sending a WhatsApp message
|--------------------------------------------------------------------------
|
| Asked for by §2 and §15, alongside SMS and email.
|
| ---------------------------------------------------------------------------
| Why this is not just "SMS with a different URL"
| ---------------------------------------------------------------------------
|
| WhatsApp is not an open channel. A business may only start a conversation
| with a pre-approved *template*, and may send free text only inside the
| 24 hours after the customer last wrote. That is Meta's rule, not a
| provider's, and it is not something a config key can opt out of.
|
| So a WhatsApp message here carries a template name and its variables, and
| the plain-text body is a fallback used by the log driver and by resellers
| that accept one. A design that pretended free text always worked would send
| nothing at all in production and look fine in testing.
|
| ---------------------------------------------------------------------------
| Two ways in, and `log` is the default
| ---------------------------------------------------------------------------
|
|   cloud  Meta's own WhatsApp Cloud API. Free tier, no reseller, but you
|          need a Meta Business account and an approved number.
|   http   any Indian BSP - Gupshup, AiSensy, Interakt, Wati. All of them are
|          "POST these parameters to this URL", so they are one driver and a
|          few settings rather than four classes.
|
| `log` writes to the log and sends nothing, which is what lets the whole
| thing be tried on a laptop. Same reasoning as config/sms.php.
|
*/

return [

    'driver' => env('WHATSAPP_DRIVER', 'log'),

    'drivers' => [

        'log' => [
            'channel' => env('WHATSAPP_LOG_CHANNEL', 'stack'),
        ],

        /*
        | Meta's WhatsApp Cloud API.
        |
        | The phone_number_id is NOT the phone number - it is the id Meta
        | shows beside it in the dashboard, and using the number instead is
        | the commonest reason a first attempt returns 404.
        */
        'cloud' => [
            'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID', ''),
            'token' => env('WHATSAPP_TOKEN', ''),
            'api' => env('WHATSAPP_API', 'https://graph.facebook.com/v21.0'),

            // Templates are approved per language. "en" is not the same as
            // "en_US" to Meta, and the wrong one is rejected.
            'language' => env('WHATSAPP_LANGUAGE', 'en'),

            'timeout' => (int) env('WHATSAPP_TIMEOUT', 10),
        ],

        /*
        | Any reseller. Same shape as the SMS http driver, for the same
        | reason: they differ in what they call the recipient and where they
        | want the key, and in nothing else that matters here.
        */
        'http' => [
            'url' => env('WHATSAPP_HTTP_URL', ''),
            'method' => env('WHATSAPP_HTTP_METHOD', 'POST'),
            'to_field' => env('WHATSAPP_HTTP_TO_FIELD', 'to'),
            'message_field' => env('WHATSAPP_HTTP_MESSAGE_FIELD', 'message'),
            'template_field' => env('WHATSAPP_HTTP_TEMPLATE_FIELD', 'template'),
            'params' => env('WHATSAPP_HTTP_PARAMS', ''),
            'headers' => env('WHATSAPP_HTTP_HEADERS', ''),
            'timeout' => (int) env('WHATSAPP_HTTP_TIMEOUT', 10),
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | Templates
    |----------------------------------------------------------------------
    |
    | The name on the left is what this system calls it; the value is what it
    | is approved as in the provider's dashboard. Keeping the mapping here
    | means a restaurant whose template is called something else changes a
    | setting rather than waiting for a release.
    */
    'templates' => [
        'order_placed' => env('WHATSAPP_TEMPLATE_ORDER', 'order_placed'),
        'order_ready' => env('WHATSAPP_TEMPLATE_READY', 'order_ready'),
        'bill' => env('WHATSAPP_TEMPLATE_BILL', 'bill_ready'),
        'reminder' => env('WHATSAPP_TEMPLATE_REMINDER', 'payment_reminder'),
        'reservation' => env('WHATSAPP_TEMPLATE_RESERVATION', 'reservation_confirmed'),
    ],
];
