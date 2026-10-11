<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One attempt to pay online (§11).
 *
 * The bridge between something this system is owed for - a table's sitting, a
 * web order - and an order opened with a provider. It exists because those two
 * are not the same thing and neither can be derived from the other: a guest
 * may abandon a checkout and start again, and a provider's order id has to
 * survive that.
 *
 * §11 asks for "Pending, Authorized, Paid, Failed, Refunded". `authorized` is
 * kept even though Razorpay's auto-capture skips it: a provider configured for
 * manual capture stops there, and a status vocabulary that could not express
 * it would be a vocabulary somebody has to work around.
 */
class PaymentIntent extends Model
{
    use BelongsToShop;

    public const PENDING = 'pending';

    public const AUTHORIZED = 'authorized';

    public const PAID = 'paid';

    public const FAILED = 'failed';

    public const REFUNDED = 'refunded';

    /** @var array<string, array{label: string, tone: string}> */
    public const STATUSES = [
        self::PENDING => ['label' => 'Pending', 'tone' => 'warning'],
        self::AUTHORIZED => ['label' => 'Authorized', 'tone' => 'info'],
        self::PAID => ['label' => 'Paid', 'tone' => 'success'],
        self::FAILED => ['label' => 'Failed', 'tone' => 'danger'],
        self::REFUNDED => ['label' => 'Refunded', 'tone' => ''],
    ];

    protected $fillable = [
        'shop_id', 'payable_type', 'payable_id', 'reference',
        'provider', 'provider_order_id', 'provider_payment_id',
        'amount', 'currency', 'status', 'payload', 'failure_reason',
        'paid_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payload' => 'array',
            'paid_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /* --------------------------------------------------------- relations */

    /** What is being paid for - a table sitting, or a web order. */
    public function payable(): \Illuminate\Database\Eloquent\Relations\MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The money actually taken, once there is any.
     *
     * A Payment row is the shop's record; this is the provider's. They are
     * deliberately separate: a refund, a chargeback or a settlement delay
     * happens to one and not the other.
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /* ------------------------------------------------------------ scopes */

    /** Intents still worth handing back to a guest who came back. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [self::PENDING, self::AUTHORIZED])
            ->where(fn (Builder $q) => $q
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()));
    }

    public function scopeOfStatus(Builder $query, ?string $status): Builder
    {
        return blank($status) ? $query : $query->where('status', $status);
    }

    /* --------------------------------------------------------- behaviour */

    public function isPaid(): bool
    {
        return $this->status === self::PAID;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::PENDING, self::AUTHORIZED], true)
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status]['label'] ?? Str::headline((string) $this->status);
    }

    public function statusTone(): string
    {
        return self::STATUSES[$this->status]['tone'] ?? '';
    }

    /**
     * A reference the provider echoes back, and a human can read on a bank
     * statement.
     *
     * Deliberately not the primary key: the id is sequential, and a reference
     * that tells a stranger how many payments a restaurant has taken is a
     * reference that should not be printed.
     */
    public static function freshReference(): string
    {
        return 'PI-'.now()->format('ymd').'-'.Str::upper(Str::random(8));
    }
}
