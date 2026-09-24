<?php

/*
|--------------------------------------------------------------------------
| Database backups (§17)
|--------------------------------------------------------------------------
|
| Read by App\Console\Commands\BackupDatabase, which writes the dumps, and by
| App\Services\SystemHealthService, which reports on them. Both read it from
| here rather than calling storage_path() themselves, for two reasons:
|
|   1. A server with a separate backup volume - which is the whole point of a
|      backup - can point at it without a code change. A dump sitting on the
|      same disk as the database it came from survives a bad deploy and not
|      much else.
|
|   2. The test suite can point it somewhere disposable. Before this file
|      existed the path was hard-coded, and a test that cleared the backup
|      directory to check the "no backups yet" state deleted the developer's
|      real dumps. That is not a hypothetical; it happened while this was
|      being written.
|
*/

return [

    /*
    | Where dumps are written. Anything outside the application directory
    | needs to exist and be writable by the web/cron user - the command will
    | create it, but it cannot create a mount point.
    */
    'path' => env('BACKUP_PATH', storage_path('app/backups')),

    /*
    | How many dumps to keep.
    |
    | Counted in files, not days, and deliberately: a server whose scheduler
    | was down for a week should not wake up and delete every backup it has
    | because they are all "too old". See BackupDatabase::prune().
    */
    'keep' => (int) env('BACKUP_KEEP', 14),

    /*
    | How stale the newest dump may be before the health screen calls it a
    | fault. Thirty-six hours rather than twenty-four so a single missed
    | nightly run is a warning to look at in the morning rather than an alarm
    | at 03:46.
    */
    'stale_hours' => (int) env('BACKUP_STALE_HOURS', 36),

];
