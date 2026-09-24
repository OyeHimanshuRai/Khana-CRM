<?php

/*
|--------------------------------------------------------------------------
| Sending a text message
|--------------------------------------------------------------------------
|
| Used by the optional OTP step on the guest's QR journey (§3.8) and by
| anything else that needs to reach a phone.
|
| Deliberately provider-agnostic. India has a dozen bulk-SMS providers -
| MSG91, Fast2SMS, Textlocal, Kaleyra, Gupshup - and every one of them is
| "POST these parameters to this URL". Hard-coding one would be guessing at
| which the restaurant already pays for, so the `http` driver is a template
| that fits all of them and the choice is a setting rather than a release.
|
| The default is `log`, which writes the message to the log and returns
| success. That is not a placeholder: it means OTP can be switched on,
| tested end to end and demonstrated on a laptop with no account anywhere,
| and the code that reads the log is the same code that would read the SMS.
| A system that cannot be tried without a paid account does not get tried.
|
*/

return [

    /*
    | log  - write it to the log. Works everywhere, sends nothing.
    | http - POST or GET to any provider's endpoint.
    */
    'driver' => env('SMS_DRIVER', 'log'),

    'from' => env('SMS_FROM', ''),

    'drivers' => [

        'log' => [
            /*
             | The channel to write to. Its own channel rather than the
             | application log, because a one-time code in a shared log file
             | read by everybody is worse than no code at all - and a
             | dedicated channel can be given its own retention.
             */
            'channel' => env('SMS_LOG_CHANNEL', 'stack'),
        ],

        'http' => [
            'url' => env('SMS_HTTP_URL', ''),
            'method' => env('SMS_HTTP_METHOD', 'POST'),

            /*
             | The provider's parameter names. Whatever they call the
             | recipient and the body, say so here and nothing else has to
             | know.
             |
             |   MSG91     to=mobiles     message=message   + authkey
             |   Fast2SMS  to=numbers     message=message   + authorization header
             |   Textlocal to=numbers     message=message   + apikey
             */
            'to_field' => env('SMS_HTTP_TO_FIELD', 'to'),
            'message_field' => env('SMS_HTTP_MESSAGE_FIELD', 'message'),

            /*
             | Anything else the provider wants on every request - an API
             | key, a sender id, a route number, a template id. JSON in the
             | environment so a provider that needs five extra parameters
             | does not need five new config keys.
             */
            'params' => env('SMS_HTTP_PARAMS', ''),
            'headers' => env('SMS_HTTP_HEADERS', ''),

            // A phone is not worth hanging a guest's checkout on.
            'timeout' => (int) env('SMS_HTTP_TIMEOUT', 8),
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | One-time codes
    |----------------------------------------------------------------------
    */
    'otp' => [
        'length' => 6,

        // Long enough to read a message and type it, short enough that a
        // stolen code is worthless by the time it is used.
        'ttl_minutes' => 10,

        /*
         | Wrong guesses before the code is burnt. Six digits is a million
         | combinations, so five guesses is not a security boundary on its
         | own - the throttle is. This stops the patient rather than the
         | fast.
         */
        'max_attempts' => 5,

        // How long before the same number may be sent another code.
        'resend_seconds' => 60,

        'message' => ':code is your verification code for :shop. It expires in :minutes minutes.',
    ],
];
