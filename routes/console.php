<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Payment reminders.
|
| Two ticks rather than one, because the two halves fail differently.
|
| Scheduling runs once, early, and only reads invoices - if it is late,
| nothing is lost. Sending runs every half hour through the day so a
| customer who pays at eleven is not chased at noon; the service re-checks
| every invoice at the moment of sending and skips the ones now settled.
|
| Both are idempotent: a missed run catches up, and a doubled run sends
| nothing twice. See App\Services\ReminderService.
*/
Schedule::command('reminders:schedule')
    ->dailyAt('08:30')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('reminders:dispatch')
    ->everyThirtyMinutes()
    ->between('09:00', '19:00')
    ->withoutOverlapping()
    ->runInBackground();

/*
| Notifications (SRS 15).
|
| Two ticks, and the reason is the same one that split the reminder engine:
| the two halves fail differently.
|
| The morning sweep is the one that matters - it is what puts "this till was
| never closed" in front of somebody before the shop opens. It runs early
| enough to be read with the first cup of tea.
|
| The afternoon tick exists because stock runs low during the day, and a shop
| that opened at nine should not have to wait until tomorrow to be told about
| something that became true at eleven.
|
| Idempotent by construction: AlertService writes through a unique dedupe
| key, so running twice cannot double-raise, and a missed run catches up.
| --prune only on the morning run; expiry housekeeping once a day is plenty.
*/
Schedule::command('alerts:sweep --prune')
    ->dailyAt('07:45')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('alerts:sweep')
    ->dailyAt('15:00')
    ->withoutOverlapping()
    ->runInBackground();

/*
| Delivery-log housekeeping.
|
| The table gains a row for every email the app sends, so it needs a ceiling.
| 90 days is long enough to answer "did that welcome email ever go out?" and
| short enough that the table stays small. Pruning is also available by hand
| from Marketing > Email Logs.
*/
Schedule::command('email:prune-logs --days=90')
    ->weeklyOn(0, '03:15')
    ->withoutOverlapping();

/*
| Subscriptions (SRS 21).
|
| Once a day, early, and it is the least urgent job on this page: expiry is
| enforced on every request by EnsureTenantIsActive, so a sweep that does not
| run leaves stale-looking screens rather than free service. See
| App\Console\Commands\SweepSubscriptions.
|
| Before the alert sweep, so a tenant suspended this morning is suspended
| before anything else spends time raising notifications for it.
*/
Schedule::command('subscriptions:sweep')
    ->dailyAt('07:30')
    ->withoutOverlapping()
    ->runInBackground();

/*
| Campaigns (SRS 15, 21).
|
| Every ten minutes, in daytime hours only. A batch per run rather than a
| campaign per run - see App\Console\Commands\SendCampaigns for why - so a
| large audience goes out over an hour or two rather than in one burst that
| gets the account rate-limited.
|
| Between 09:00 and 20:00 because a marketing message at six in the morning is
| how a restaurant gets itself blocked. Payment reminders keep their own,
| tighter window.
*/
Schedule::command('campaigns:send')
    ->everyTenMinutes()
    ->between('09:00', '20:00')
    ->withoutOverlapping()
    ->runInBackground();

/*
| Database backups (§17).
|
| At 03:45, which is after the kitchen has closed everywhere this platform
| runs and before the email prune at 03:15 on Sundays would collide with it -
| a dump and a delete on the same tables at the same moment is avoidable
| contention for no gain.
|
| `--single-transaction` inside the command means this does not lock the till
| out, so the time is chosen for the size of the dump rather than for safety.
|
| Fourteen kept, which is two weeks: long enough to notice a corruption that
| happened on a Monday and reach back past it, short enough that a small VPS
| does not fill up with them. See App\Console\Commands\BackupDatabase.
|
| `runInBackground` is deliberately NOT set. A backup that overlaps the next
| night's backup is a real problem, and `withoutOverlapping` only works on a
| job the scheduler is still holding.
*/
Schedule::command('backup:run --keep=14')
    ->dailyAt('03:45')
    ->withoutOverlapping();
