<?php

use Illuminate\Support\Facades\Schedule;

/*
| ERP's own nightly work. Sending and applying events is scheduled by the
| kit (outbox:deliver, inbox:work, outbox:prune).
*/

// Every level must equal the sum of its movements - see ReconcileStock.
Schedule::command('erp:reconcile')->dailyAt('04:30');
