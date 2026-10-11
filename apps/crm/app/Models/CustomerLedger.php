<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * One entry on a customer's account.
 *
 * Append-only, and the thing `customers.balance` is a cache of.
 *
 *   debit   the customer owes more  (an invoice)
 *   credit  the customer owes less  (a payment, a return, a write-off)
 *
 * balance_after is positive when the customer is in debt.
 */
class CustomerLedger extends Model
{
    use BelongsToShop;

    public const OPENING = 'opening';

    public const INVOICE = 'invoice';

    public const PAYMENT = 'payment';

    public const SALE_RETURN = 'return';

    public const REFUND = 'refund';

    public const WRITE_OFF = 'write_off';

    public const ADJUSTMENT = 'adjustment';

    /** @var array<string, string> */
    public const TYPES = [
        self::OPENING => 'Opening balance',
        self::INVOICE => 'Invoice',
        self::PAYMENT => 'Payment received',
        self::SALE_RETURN => 'Sales return',
        self::REFUND => 'Refund paid',
        self::WRITE_OFF => 'Written off',
        self::ADJUSTMENT => 'Adjustment',
    ];

    protected $fillable = [
        'shop_id', 'customer_id', 'type',
        'reference_type', 'reference_id', 'description',
        'debit', 'credit', 'balance_after', 'entered_at',
        'user_id', 'user_name',
    ];

    protected function casts(): array
    {
        return [
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'entered_at' => 'datetime',
        ];
    }

    /* --------------------------------------------------------- relations */

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /* ------------------------------------------------------------ scopes */

    public function scopeBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn (Builder $q, string $d) => $q->whereDate('entered_at', '>=', $d))
            ->when($to, fn (Builder $q, string $d) => $q->whereDate('entered_at', '<=', $d));
    }

    public function scopeOfType(Builder $query, ?string $type): Builder
    {
        return blank($type) ? $query : $query->where('type', $type);
    }

    /** The statement's order: as entered, ties broken by insertion. */
    public function scopeStatementOrder(Builder $query): Builder
    {
        return $query->orderBy('entered_at')->orderBy('id');
    }

    /* --------------------------------------------------------- behaviour */

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? Str::headline($this->type);
    }

    /** Signed amount: positive increases the debt. */
    public function amount(): float
    {
        return (float) $this->debit - (float) $this->credit;
    }
}
