<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Khana\Kit\Support\Money;

/**
 * Money an outlet paid the platform for its subscription.
 *
 * @property int $id
 * @property string|null $reference
 * @property int $subscription_id
 * @property int $amount_paise
 * @property string $method
 * @property \Illuminate\Support\Carbon $paid_at
 * @property \Illuminate\Support\Carbon|null $period_start
 * @property \Illuminate\Support\Carbon|null $period_end
 */
class PlatformPayment extends Model
{
    public const METHODS = ['manual', 'bank', 'upi', 'razorpay'];

    protected $fillable = [
        'subscription_id', 'tenant_id', 'outlet_id', 'amount_paise', 'method', 'provider_reference',
        'paid_at', 'period_start', 'period_end', 'note', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'amount_paise' => 'integer',
            'paid_at' => 'datetime',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
        ];
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function amount(): Money
    {
        return Money::paise($this->amount_paise);
    }
}
