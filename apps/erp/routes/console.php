<?php

use Illuminate\Support\Facades\Schedule;

/*
| ERP's own scheduled work. Sending and applying events is scheduled by the
| kit (outbox:deliver, inbox:work, outbox:prune).
*/

// Every level must equal the sum of its movements - see ReconcileStock.
Schedule::command('erp:reconcile')->dailyAt('04:30');

// The nightly checks against CRM and Accounts. Hourly, because each outlet's
// night is its own: it is checked in the first run after its day closes,
// and a check left with a problem is tried again the hour after. Ten past,
// so a bill settled on the stroke of the cutoff has been heard of.
Schedule::command('erp:nightly')->hourlyAt(10)->withoutOverlapping();

// Events waiting too long to be sent, or received and failed - see WatchEvents.
Schedule::command('erp:watch')->everyTenMinutes();
