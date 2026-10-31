<?php

/*
|--------------------------------------------------------------------------
| Payment reminders
|--------------------------------------------------------------------------
|
| What the shop says to a customer about money owed, and when.
|
| Every trigger can be switched off without touching code, and the timing is
| a number here rather than a constant buried in a job - because "remind
| them three days before, not seven" is a business decision that will change
| and should not need a developer.
|
| `offset_days` is measured against the invoice's due date:
|
|     negative   before it falls due
|     zero       on the day
|     positive   after it has passed
|
| The scheduler writes one row per invoice per trigger per channel and never
| a second, so it is safe to run as often as you like.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | Off leaves the scheduler running but sending nothing, which is what a
    | shop wants while it is still loading historical invoices.
    |
    */

    'enabled' => env('REMINDERS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | How many to send per run
    |--------------------------------------------------------------------------
    |
    | A cap, so a first run against a year of unpaid invoices does not empty
    | the mail quota in one go and get the domain blocked.
    |
    */

    'per_run' => env('REMINDERS_PER_RUN', 200),

    /** Give up on a reminder after this many failed attempts. */
    'max_attempts' => 3,

    /*
    |--------------------------------------------------------------------------
    | Triggers
    |--------------------------------------------------------------------------
    |
    | `escalate` marks the ones that also raise an internal alert rather than
    | only writing to the customer - the SRS's "escalate to admin/manager".
    |
    */

    'triggers' => [

        'upcoming' => [
            'label' => 'Before it falls due',
            'enabled' => true,
            'offset_days' => -3,
            'subject' => 'A payment to :shop falls due on :due_date',
            'escalate' => false,
        ],

        'due_today' => [
            'label' => 'On the due date',
            'enabled' => true,
            'offset_days' => 0,
            'subject' => 'Your payment to :shop is due today',
            'escalate' => false,
        ],

        'overdue' => [
            'label' => 'Shortly after it passes',
            'enabled' => true,
            'offset_days' => 7,
            'subject' => 'Overdue: :amount owed to :shop',
            'escalate' => false,
        ],

        'long_overdue' => [
            'label' => 'Long overdue',
            'enabled' => true,
            'offset_days' => 30,
            'subject' => 'Final reminder: :amount owed to :shop',
            // A month past due is the shop's problem, not only the
            // customer's, so somebody inside is told as well.
            'escalate' => true,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Channels
    |--------------------------------------------------------------------------
    |
    | Email is the only one that sends today. SMS and WhatsApp are declared
    | because the SRS asks for them and because having the shape in place
    | means adding a provider is a driver, not a redesign - but they are off,
    | and the scheduler will not queue what it cannot deliver.
    |
    */

    'channels' => [
        'email' => [
            'label' => 'Email',
            'enabled' => true,
        ],
        'sms' => [
            'label' => 'SMS',
            'enabled' => env('REMINDERS_SMS_ENABLED', false),
        ],
        'whatsapp' => [
            'label' => 'WhatsApp',
            'enabled' => env('REMINDERS_WHATSAPP_ENABLED', false),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Quiet hours
    |--------------------------------------------------------------------------
    |
    | Reminders queued outside these hours wait. Nobody wants a demand for
    | money at half past five in the morning, and a shop that sends them
    | stops being read.
    |
    */

    'send_between' => [
        'from' => '09:00',
        'to' => '19:00',
    ],

    /*
    |--------------------------------------------------------------------------
    | Housekeeping
    |--------------------------------------------------------------------------
    */

    // Reminders older than this are pruned by reminders:prune.
    'retain_days' => 365,

];
