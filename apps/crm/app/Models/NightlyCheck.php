<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What one nightly check found at one outlet for one trading day.
 *
 * Written by App\Services\NightlyChecks and read by people. Events carry the
 * day's truth live and the plumbing retries until each is delivered; this is
 * for the one that was never recorded or was thrown away, found and put right
 * on a later pass.
 *
 * One row per outlet, day and check. Keyed by Accounts' ids (ulids), not a
 * shop row, because the command runs with nobody signed in and no outlet in
 * context - so this is a plain model with no shop scope.
 *
 * `availability` is the only check here today: CRM's sold-out list for an
 * outlet against what ERP last said. `reference` (CRM's copy of Accounts'
 * outlets and businesses) and `balances` are expected to join it, which is
 * why the result shape and the command already allow for more than one.
 */
class NightlyCheck extends Model
{
    /** The only time kept is `ran_at`; the table carries no created/updated pair. */
    public $timestamps = false;

    public const AVAILABILITY = 'availability';

    public const REFERENCE = 'reference';

    public const BALANCES = 'balances';

    /** The checks, in the order a person reads them, in plain words. */
    public const CHECKS = [
        self::AVAILABILITY => 'Sold-out against ERP',
    ];

    public const OK = 'ok';

    public const REPAIRED = 'repaired';

    public const PROBLEM = 'problem';

    public const SKIPPED = 'skipped';

    public const STATUSES = [
        self::OK => 'All in order',
        self::REPAIRED => 'Put right',
        self::PROBLEM => 'Needs a look',
        self::SKIPPED => 'Not needed here',
    ];

    /** Results that stand: the check is done for that outlet and day. */
    public const FINAL = [self::OK, self::REPAIRED, self::SKIPPED];

    protected $fillable = [
        'tenant_id', 'outlet_id', 'business_date', 'check', 'status', 'found', 'repaired', 'detail', 'ran_at',
    ];

    /*
    | `business_date` is deliberately not cast to a date. It is one half of
    | the key a check is looked up by, and a cast would store it with a time
    | on some databases and without on others - the plain day compares the
    | same everywhere.
    */
    protected function casts(): array
    {
        return ['found' => 'integer', 'repaired' => 'integer', 'ran_at' => 'datetime'];
    }

    public function isFinal(): bool
    {
        return in_array($this->status, self::FINAL, true);
    }

    public function checkLabel(): string
    {
        return self::CHECKS[$this->check] ?? $this->check;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
