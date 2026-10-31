<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money sent back through the gateway (SRS 11).
 *
 * Append-only. A failed refund stays failed and a retry is a new row - see
 * the migration for why the failures are the part worth keeping.
 *
 * @property float $amount
 * @property string $status
 */
class PaymentRefund extends Model
{
    use BelongsToShop;

    public const PENDING = 'pending';

    /** The provider has sent it. Days from the guest's bank, not minutes. */
    public const PROCESSED = 'processed';

    public const FAILED = 'failed';

    protected $fillable = [
        'shop_id', 'payment_intent_id', 'reference',
        'amount', 'currency', 'reason', 'status',
        'provider_refund_id', 'error', 'user_id', 'refunded_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'refunded_at' => 'datetime',
        ];
    }

    /* --------------------------------------------------------- relations */

    /** @return BelongsTo<PaymentIntent, $this> */
    public function intent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class, 'payment_intent_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /* ------------------------------------------------------------ scopes */

    /**
     * Refunds that are actually money out of the door.
     *
     * A failed one never left, so counting it against what may still be
     * refunded would quietly shrink a guest's entitlement every time the
     * provider had a bad afternoon.
     */
    public function scopeCounted(Builder $query): Builder
    {
        return $query->whereIn('status', [self::PENDING, self::PROCESSED]);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::FAILED);
    }

    /* --------------------------------------------------------- behaviour */

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::PROCESSED => 'Sent',
            self::FAILED => 'Failed',
            default => 'With the provider',
        };
    }

    public function statusTone(): string
    {
        return match ($this->status) {
            self::PROCESSED => 'success',
            self::FAILED => 'danger',
            default => 'warning',
        };
    }
}
