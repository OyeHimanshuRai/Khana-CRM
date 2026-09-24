<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * One entry on a supplier's account.
 *
 *   credit  the shop owes the supplier more  (a bill)
 *   debit   the shop owes the supplier less  (a payment, a return)
 *
 * balance_after is positive when the shop is in debt to them - the opposite
 * convention to the customer ledger, and stated in both places because
 * getting it backwards is the easiest mistake there is here.
 */
class SupplierLedger extends Model
{
    use BelongsToShop;

    public const OPENING = 'opening';

    public const BILL = 'bill';

    public const PAYMENT = 'payment';

    public const PURCHASE_RETURN = 'return';

    public const ADJUSTMENT = 'adjustment';

    /** @var array<string, string> */
    public const TYPES = [
        self::OPENING => 'Opening balance',
        self::BILL => 'Purchase bill',
        self::PAYMENT => 'Payment made',
        self::PURCHASE_RETURN => 'Purchase return',
        self::ADJUSTMENT => 'Adjustment',
    ];

    protected $fillable = [
        'shop_id', 'supplier_id', 'type',
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

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /* ------------------------------------------------------------ scopes */

    public function scopeStatementOrder(Builder $query): Builder
    {
        return $query->orderBy('entered_at')->orderBy('id');
    }

    public function scopeBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn (Builder $q, string $d) => $q->whereDate('entered_at', '>=', $d))
            ->when($to, fn (Builder $q, string $d) => $q->whereDate('entered_at', '<=', $d));
    }

    /* --------------------------------------------------------- behaviour */

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? Str::headline($this->type);
    }

    /** Signed amount: positive increases what the shop owes. */
    public function amount(): float
    {
        return (float) $this->credit - (float) $this->debit;
    }
}
