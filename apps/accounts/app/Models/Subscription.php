<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One outlet on one plan.
 *
 * ---------------------------------------------------------------------------
 * The state is worked out from dates, never stored
 * ---------------------------------------------------------------------------
 *
 * `ends_at` means "works until". A trial sets it to the trial's end; a
 * payment moves it forward. What the products are told follows from it and
 * the clock:
 *
 *   trial      within a free trial
 *   active     paid up (or perpetual: ends_at is null)
 *   grace      the term has run out, for grace_days more (3 by default)
 *   suspended  read and export only: unpaid past grace, or a product the
 *              outlet dropped - kept readable for 90 days
 *   ended      after those 90 days
 *
 * A product is dropped when the subscription is cancelled (from the end of
 * the paid term) or replaced by a plan that no longer covers it.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $outlet_id
 * @property int $plan_id
 * @property string $billing_period
 * @property int $price_paise
 * @property \Illuminate\Support\Carbon $starts_at
 * @property \Illuminate\Support\Carbon|null $trial_ends_at
 * @property \Illuminate\Support\Carbon|null $ends_at
 * @property int $grace_days
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property \Illuminate\Support\Carbon|null $replaced_at
 * @property-read Plan $plan
 * @property-read Outlet $outlet
 * @property-read Tenant $tenant
 */
class Subscription extends Model
{
    public const TRIAL = 'trial';

    public const ACTIVE = 'active';

    public const GRACE = 'grace';

    public const SUSPENDED = 'suspended';

    public const ENDED = 'ended';

    /** Days a dropped or unpaid product stays readable and exportable. */
    public const RETENTION_DAYS = 90;

    protected $fillable = [
        'tenant_id', 'outlet_id', 'plan_id', 'billing_period', 'price_paise',
        'starts_at', 'trial_ends_at', 'ends_at', 'grace_days', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'price_paise' => 'integer',
            'grace_days' => 'integer',
            'starts_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'replaced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsTo<Outlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return HasMany<PlatformPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(PlatformPayment::class);
    }

    /**
     * What the products are told, and until when it holds.
     *
     * @return array{0: string, 1: CarbonImmutable|null}
     */
    public function status(?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? now());

        $dropped = collect([$this->replaced_at, $this->cancelled_at])
            ->filter(fn ($at) => $at !== null && ! $at->greaterThan($now))
            ->min();

        if ($dropped !== null) {
            return $this->afterTheEnd(CarbonImmutable::instance($dropped), $now);
        }

        if ($this->ends_at === null) {
            return [self::ACTIVE, null];
        }

        $ends = CarbonImmutable::instance($this->ends_at);

        if ($ends->greaterThan($now)) {
            $onTrial = $this->trial_ends_at !== null && $this->trial_ends_at->greaterThan($now);

            return [$onTrial ? self::TRIAL : self::ACTIVE, $ends];
        }

        $graceEnds = $ends->addDays($this->grace_days);

        if ($graceEnds->greaterThan($now)) {
            return [self::GRACE, $graceEnds];
        }

        return $this->afterTheEnd($graceEnds, $now);
    }

    public function state(?CarbonInterface $now = null): string
    {
        return $this->status($now)[0];
    }

    /** Still working: trial, active or grace. */
    public function isUsable(?CarbonInterface $now = null): bool
    {
        return in_array($this->state($now), [self::TRIAL, self::ACTIVE, self::GRACE], true);
    }

    /**
     * Renewal extends from whichever is later, the current end or now: paying
     * early keeps the days already bought, paying late does not buy back the
     * days missed.
     */
    public function nextTermEnd(?string $period = null): CarbonImmutable
    {
        $base = $this->ends_at !== null && $this->ends_at->isFuture()
            ? CarbonImmutable::instance($this->ends_at)
            : CarbonImmutable::now();

        return ($period ?? $this->billing_period) === Plan::YEARLY ? $base->addYear() : $base->addMonth();
    }

    public function stateLabel(): string
    {
        return match ($this->state()) {
            self::TRIAL => 'Trial',
            self::ACTIVE => 'Active',
            self::GRACE => 'Payment due',
            self::SUSPENDED => 'Suspended',
            default => 'Ended',
        };
    }

    public function stateTone(): string
    {
        return match ($this->state()) {
            self::ACTIVE => 'success',
            self::TRIAL => 'info',
            self::GRACE => 'warning',
            default => 'danger',
        };
    }

    /**
     * @return array{0: string, 1: CarbonImmutable|null}
     */
    private function afterTheEnd(CarbonImmutable $end, CarbonImmutable $now): array
    {
        $archive = $end->addDays(self::RETENTION_DAYS);

        return $archive->greaterThan($now) ? [self::SUSPENDED, $archive] : [self::ENDED, null];
    }
}
