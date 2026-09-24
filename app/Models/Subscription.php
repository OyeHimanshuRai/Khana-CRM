<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One business's subscription to one plan (SRS 2, 21).
 *
 * ---------------------------------------------------------------------------
 * The state is computed, never stored
 * ---------------------------------------------------------------------------
 *
 * See the migration for why. The short version: a stored status and a stored
 * date say the same thing, and the moment they disagree the date is right and
 * the status is a bug that has been sitting in the table for a fortnight.
 *
 * `ends_at` is the whole model. It means "works until". A trial sets it to
 * the end of the trial; a payment moves it forward. Everything else here
 * reads it.
 *
 * @property int $id
 * @property \Illuminate\Support\Carbon|null $trial_ends_at
 * @property \Illuminate\Support\Carbon|null $ends_at
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property int $grace_days
 * @property-read Plan $plan
 */
class Subscription extends Model
{
    public const TRIALING = 'trialing';

    public const ACTIVE = 'active';

    /** Term is up, grace is not: works, and says so loudly. */
    public const PAST_DUE = 'past_due';

    public const EXPIRED = 'expired';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'tenant_id', 'shop_id', 'plan_id',
        'billing_period', 'price', 'currency',
        'starts_at', 'trial_ends_at', 'ends_at', 'grace_days', 'cancelled_at',
        'note', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'starts_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'grace_days' => 'integer',
        ];
    }

    /* --------------------------------------------------------- relations */

    /** @return BelongsTo<Tenant, $this> */
    /**
     * The outlet this subscription was sold for, or null for a blanket row.
     *
     * Null is not missing data. It is a subscription taken out before
     * billing moved per-outlet, and it covers every shop in its tenant -
     * see the migration, which explains why the two shapes coexist rather
     * than one being converted into the other.
     *
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /** Whether this row is sold per outlet rather than covering the tenant. */
    public function isPerShop(): bool
    {
        return $this->shop_id !== null;
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return HasMany<SubscriptionPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ------------------------------------------------------------ scopes */

    /**
     * Subscriptions whose paid term has run out, grace or no grace.
     *
     * Deliberately stops at `ends_at` and does not try to add `grace_days`
     * in SQL. The interval arithmetic differs between MySQL and SQLite, and
     * the grace window is a business rule rather than a query: the sweep
     * narrows to these rows - a handful even on a large install, because
     * they are the ones nobody has renewed - and asks each one's state() in
     * PHP, where the rule lives once.
     */
    public function scopeTermEnded(Builder $query): Builder
    {
        return $query
            ->whereNull('cancelled_at')
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', now());
    }

    /* ------------------------------------------------------------- state */

    /**
     * What this subscription is, right now, from the clock.
     */
    public function state(): string
    {
        if ($this->cancelled_at !== null && ! $this->cancelled_at->isFuture()) {
            return self::CANCELLED;
        }

        // Perpetual. The answer for an in-house account and for an install
        // that is not really being sold.
        if ($this->ends_at === null) {
            return self::ACTIVE;
        }

        if ($this->ends_at->isFuture()) {
            return $this->onTrial() ? self::TRIALING : self::ACTIVE;
        }

        return $this->graceEndsAt()?->isFuture() ? self::PAST_DUE : self::EXPIRED;
    }

    /** Free right now because the trial has not run out. */
    public function onTrial(): bool
    {
        return $this->trial_ends_at !== null && $this->trial_ends_at->isFuture();
    }

    /**
     * May the business trade?
     *
     * True through the trial, the paid term and the grace window; false once
     * it has lapsed or been cancelled. This is the single question the
     * middleware asks.
     */
    public function isUsable(): bool
    {
        return ! in_array($this->state(), [self::EXPIRED, self::CANCELLED], true);
    }

    /** Past the term but inside grace - the state worth warning about. */
    public function needsAttention(): bool
    {
        return $this->state() === self::PAST_DUE;
    }

    public function graceEndsAt(): ?CarbonInterface
    {
        return $this->ends_at?->copy()->addDays($this->grace_days);
    }

    /**
     * Whole days until the term runs out; negative once it has.
     *
     * Null for a perpetual subscription, because "how long left" has no
     * answer rather than a large one.
     */
    public function daysLeft(): ?int
    {
        return $this->ends_at === null
            ? null
            : now()->startOfDay()->diffInDays($this->ends_at->copy()->startOfDay(), false);
    }

    /* --------------------------------------------------------- behaviour */

    /**
     * Move the term forward by one billing period.
     *
     * Renewal extends from whichever is later: the current end, or now. That
     * distinction is the whole of fair renewal arithmetic - a business that
     * pays early keeps the days it already bought, and one that pays three
     * weeks late does not get three weeks it never had.
     */
    public function extend(?string $period = null, ?CarbonInterface $from = null): CarbonInterface
    {
        $period = $period ?: $this->billing_period;

        $base = $from ?: ($this->ends_at !== null && $this->ends_at->isFuture()
            ? $this->ends_at->copy()
            : Carbon::now());

        return $period === Plan::YEARLY
            ? $base->copy()->addYear()
            : $base->copy()->addMonth();
    }

    public function stateLabel(): string
    {
        return match ($this->state()) {
            self::TRIALING => 'Trial',
            self::ACTIVE => 'Active',
            self::PAST_DUE => 'Payment due',
            self::EXPIRED => 'Expired',
            self::CANCELLED => 'Cancelled',
            default => 'Unknown',
        };
    }

    /** Badge tone used by the list and the tenant screen. */
    public function stateTone(): string
    {
        return match ($this->state()) {
            self::ACTIVE => 'success',
            self::TRIALING => 'info',
            self::PAST_DUE => 'warning',
            default => 'danger',
        };
    }
}
