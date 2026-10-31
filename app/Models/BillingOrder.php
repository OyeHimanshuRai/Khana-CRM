<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One attempt to pay the platform for a plan.
 *
 * The provider's order id and payment id are unique, so the same payment can
 * never be recorded against two orders even if two workers race.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $subscription_id
 * @property int $plan_id
 * @property string $billing_period
 * @property string $status
 */
class BillingOrder extends Model
{
    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const FAILED = 'failed';

    protected $fillable = [
        'tenant_id', 'subscription_id', 'plan_id', 'billing_period',
        'list_price', 'credit', 'taxable_amount', 'gst_rate', 'gst_amount', 'amount', 'currency',
        'reference', 'provider', 'provider_order_id', 'provider_payment_id',
        'status', 'failure_reason', 'payload',
        'subscription_payment_id', 'created_by', 'paid_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'list_price' => 'decimal:2',
            'credit' => 'decimal:2',
            'taxable_amount' => 'decimal:2',
            'gst_rate' => 'decimal:2',
            'gst_amount' => 'decimal:2',
            'amount' => 'decimal:2',
            'payload' => 'array',
            'paid_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsTo<SubscriptionPayment, $this> */
    public function subscriptionPayment(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPayment::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::PENDING)
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function isPaid(): bool
    {
        return $this->status === self::PAID;
    }

    /** A reference the provider echoes back; not the sequential id. */
    public static function freshReference(): string
    {
        return 'BO-'.now()->format('ymd').'-'.Str::upper(Str::random(8));
    }
}
