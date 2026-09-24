<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money the platform has received from a restaurant (SRS 2, 21).
 *
 * Append-only. A payment recorded in error is corrected by recording a
 * negative one, never by editing or deleting this row - see the migration.
 *
 * Not to be confused with PaymentIntent (§11), which is a diner paying a
 * restaurant. Different money, different direction, different account.
 *
 * @property float $amount
 * @property \Illuminate\Support\Carbon $paid_at
 */
class SubscriptionPayment extends Model
{
    public const MANUAL = 'manual';

    /** @var array<int, string> */
    public const METHODS = ['manual', 'bank', 'upi', 'card', 'razorpay'];

    protected $fillable = [
        'subscription_id', 'tenant_id', 'shop_id', 'reference',
        'amount', 'currency', 'method', 'provider_reference',
        'paid_at', 'period_start', 'period_end',
        'note', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
        ];
    }

    /* --------------------------------------------------------- relations */

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /* ------------------------------------------------------------ scopes */

    /** A correction or refund rather than a receipt. */
    public function scopeCredits(Builder $query): Builder
    {
        return $query->where('amount', '<', 0);
    }

    public function isRefund(): bool
    {
        return (float) $this->amount < 0;
    }

    public function methodLabel(): string
    {
        return match ($this->method) {
            'manual' => 'Recorded by hand',
            'bank' => 'Bank transfer',
            'upi' => 'UPI',
            'card' => 'Card',
            'razorpay' => 'Razorpay',
            default => ucfirst((string) $this->method),
        };
    }

    /** The term this payment bought, as one readable phrase. */
    public function periodLine(): string
    {
        return $this->period_start->format('j M Y').' – '.$this->period_end->format('j M Y');
    }
}
