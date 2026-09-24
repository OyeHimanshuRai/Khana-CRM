<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * Money in or out.
 *
 * Never edited. A mistake is corrected with a reversing payment that points
 * back at the original - which is why `reverses_payment_id` exists and why
 * there is no update path anywhere in the app.
 */
class Payment extends Model
{
    use BelongsToShop;

    public const IN = 'in';

    public const OUT = 'out';

    /* How the money moved. */
    public const CASH = 'cash';

    public const UPI = 'upi';

    public const CARD = 'card';

    public const BANK = 'bank';

    public const CHEQUE = 'cheque';

    public const WALLET = 'wallet';

    /**
     * Payment methods, and whether each one settles immediately.
     *
     * `instant` is what decides the default status: a cheque is taken today
     * and clears later, so it starts pending; cash does not.
     *
     * @var array<string, array{label: string, instant: bool}>
     */
    public const METHODS = [
        self::CASH => ['label' => 'Cash', 'instant' => true],
        self::UPI => ['label' => 'UPI', 'instant' => true],
        self::CARD => ['label' => 'Card', 'instant' => true],
        self::BANK => ['label' => 'Bank transfer', 'instant' => true],
        self::WALLET => ['label' => 'Wallet', 'instant' => true],
        self::CHEQUE => ['label' => 'Cheque', 'instant' => false],
    ];

    public const PENDING = 'pending';

    public const CLEARED = 'cleared';

    public const BOUNCED = 'bounced';

    public const CANCELLED = 'cancelled';

    /** @var array<string, array{label: string, tone: string}> */
    public const STATUSES = [
        self::PENDING => ['label' => 'Pending', 'tone' => 'warning'],
        self::CLEARED => ['label' => 'Cleared', 'tone' => 'success'],
        self::BOUNCED => ['label' => 'Bounced', 'tone' => 'danger'],
        self::CANCELLED => ['label' => 'Cancelled', 'tone' => 'danger'],
    ];

    protected $fillable = [
        'shop_id', 'number', 'direction', 'method',
        'party_type', 'party_id', 'party_name',
        'reference_type', 'reference_id',
        'amount', 'paid_at', 'transaction_ref', 'bank_name', 'cheque_date',
        'status', 'notes', 'reverses_payment_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'cheque_date' => 'date',
        ];
    }

    /* --------------------------------------------------------- relations */

    /** The customer or supplier the money moved with. */
    public function party(): MorphTo
    {
        return $this->morphTo();
    }

    /** The invoice or bill it settles, when it settles one. */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_payment_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ------------------------------------------------------------ scopes */

    /**
     * Payments that count towards a balance.
     *
     * A pending cheque has not paid anything yet, and a bounced one never
     * did. Both stay on the record; neither reduces what is owed.
     */
    public function scopeEffective(Builder $query): Builder
    {
        return $query->where('status', self::CLEARED);
    }

    public function scopeIncoming(Builder $query): Builder
    {
        return $query->where('direction', self::IN);
    }

    public function scopeOutgoing(Builder $query): Builder
    {
        return $query->where('direction', self::OUT);
    }

    public function scopeOfMethod(Builder $query, ?string $method): Builder
    {
        return blank($method) ? $query : $query->where('method', $method);
    }

    public function scopeBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn (Builder $q, string $d) => $q->whereDate('paid_at', '>=', $d))
            ->when($to, fn (Builder $q, string $d) => $q->whereDate('paid_at', '<=', $d));
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('number', 'like', "%{$term}%")
            ->orWhere('party_name', 'like', "%{$term}%")
            ->orWhere('transaction_ref', 'like', "%{$term}%")
            ->orWhere('created_by_name', 'like', "%{$term}%"));
    }

    /* --------------------------------------------------------- behaviour */

    public function methodLabel(): string
    {
        return self::METHODS[$this->method]['label'] ?? Str::headline($this->method);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status]['label'] ?? Str::headline($this->status);
    }

    public function statusTone(): string
    {
        return self::STATUSES[$this->status]['tone'] ?? '';
    }

    public function isEffective(): bool
    {
        return $this->status === self::CLEARED;
    }

    /** Whether this method settles the moment it is taken. */
    public static function settlesImmediately(string $method): bool
    {
        return self::METHODS[$method]['instant'] ?? true;
    }

    /** The status a freshly recorded payment of this method should carry. */
    public static function initialStatus(string $method): string
    {
        return self::settlesImmediately($method) ? self::CLEARED : self::PENDING;
    }

    /** Mint the next shop-local number: RCP/MAIN/2026/00001. */
    public static function nextNumber(Shop $shop, string $direction = self::IN): string
    {
        $kind = $direction === self::OUT ? 'PAY' : 'RCP';
        $prefix = sprintf('%s/%s/%s/', $kind, $shop->code, now()->format('Y'));

        $last = static::allShops()
            ->where('shop_id', $shop->id)
            ->where('number', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('number');

        $number = $last ? ((int) Str::afterLast($last, '/')) + 1 : 1;

        return $prefix.str_pad((string) $number, 5, '0', STR_PAD_LEFT);
    }
}
