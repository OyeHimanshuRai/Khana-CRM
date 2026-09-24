<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One movement of points (SRS 15).
 *
 * Append-only. A mistake is corrected with an `adjust` row, never by editing
 * this one - see the migration for why the balance is the sum of these rather
 * than a column on the customer.
 *
 * @property int $points
 */
class LoyaltyTransaction extends Model
{
    use BelongsToShop;

    public const EARN = 'earn';

    public const REDEEM = 'redeem';

    public const EXPIRE = 'expire';

    public const ADJUST = 'adjust';

    protected $fillable = [
        'shop_id', 'customer_id', 'type', 'points', 'balance_after',
        'source_type', 'source_id', 'amount', 'expires_at', 'note', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'balance_after' => 'integer',
            'amount' => 'decimal:2',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return MorphTo<Model, $this> */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /* ------------------------------------------------------------ scopes */

    /**
     * Earn rows whose points have run out of time.
     *
     * Only `earn` rows carry an expiry: it is the points themselves that
     * lapse, and a redemption has already spent some.
     */
    public function scopeLapsed(Builder $query): Builder
    {
        return $query
            ->where('type', self::EARN)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now());
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            self::EARN => 'Earned',
            self::REDEEM => 'Redeemed',
            self::EXPIRE => 'Expired',
            default => 'Adjusted',
        };
    }

    public function typeTone(): string
    {
        return match ($this->type) {
            self::EARN => 'success',
            self::REDEEM => 'info',
            self::EXPIRE => 'muted',
            default => 'warning',
        };
    }
}
