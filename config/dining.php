<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Floor plan
    |--------------------------------------------------------------------------
    |
    | How the plan decides a table needs a person's attention. All in minutes,
    | and all deliberately settings rather than code: a fine-dining room and a
    | food court do not agree on what "too long" is.
    */
    'plan' => [
        // How often an open plan asks the server for changes. 0 turns it off.
        'refresh_seconds' => (int) env('PLAN_REFRESH_SECONDS', 10),

        // A party has been seated this long.
        'long_stay_minutes' => (int) env('PLAN_LONG_STAY_MINUTES', 90),

        // The bill was asked for and still has not been settled.
        'bill_wait_minutes' => (int) env('PLAN_BILL_WAIT_MINUTES', 10),

        // A table has been "cleaning" this long.
        'cleaning_minutes' => (int) env('PLAN_CLEANING_MINUTES', 15),

        // A booking is shown on its table from this long before its time...
        'reservation_lead_minutes' => (int) env('PLAN_RESERVATION_LEAD_MINUTES', 60),

        // ...and counts as late once this long has passed with nobody seated.
        'reservation_late_minutes' => (int) env('PLAN_RESERVATION_LATE_MINUTES', 10),
    ],

];
