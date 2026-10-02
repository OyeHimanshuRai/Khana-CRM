<?php

namespace App\Models;

use App\Models\Concerns\InOutlet;
use Illuminate\Database\Eloquent\Model;

/**
 * What one nightly check found at one outlet for one trading day.
 *
 * Written by App\Services\NightlyChecks and read by people: the page under
 * Settings lists these, and the home screen points at it when the latest
 * night left something unresolved.
 *
 * One row per outlet, day and check. A check that ended `ok`, `repaired` or
 * `skipped` is finished and is not run again; a `problem` is tried again on
 * the next run, and this row is the one that changes.
 */
class NightlyCheck extends Model
{
    use InOutlet;

    public const SALES = 'sales';

    public const MENU = 'menu';

    public const REFERENCE = 'reference';

    public const BALANCES = 'balances';

    /** The checks, in the order a person reads them, in plain words. */
    public const CHECKS = [
        self::SALES => 'Sales against CRM',
        self::MENU => 'Menu against CRM',
        self::REFERENCE => 'Business details against Accounts',
        self::BALANCES => 'Stock adds up',
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
