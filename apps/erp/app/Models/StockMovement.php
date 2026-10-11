<?php

namespace App\Models;

use App\Models\Concerns\InOutlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One line of the stock ledger: what moved, where, how much it was worth
 * and why. Written by App\Services\StockService and never changed after.
 */
class StockMovement extends Model
{
    use InOutlet;

    public const UPDATED_AT = null;

    public const OPENING = 'opening';

    public const RECEIPT = 'receipt';

    public const SUPPLIER_RETURN = 'supplier_return';

    public const TRANSFER_OUT = 'transfer_out';

    public const TRANSFER_IN = 'transfer_in';

    public const CONSUMPTION = 'consumption';

    /** A cancelled sale or kitchen line giving back what it had booked. */
    public const REVERSAL = 'reversal';

    public const WASTAGE = 'wastage';

    /** A count that found more or less than the books said. */
    public const ADJUSTMENT = 'adjustment';

    public const PRODUCTION_OUT = 'production_out';

    public const PRODUCTION_IN = 'production_in';

    /** A guest's return of something that can be sold again. */
    public const CUSTOMER_RETURN = 'customer_return';

    /*
    | Stock sent to another outlet of the business, or come from one. Kept
    | apart from TRANSFER_OUT and TRANSFER_IN, which are the two halves of a
    | move inside one outlet and always cancel out there; these do not.
    */
    public const OUTLET_OUT = 'outlet_out';

    public const OUTLET_IN = 'outlet_in';

    public const TYPES = [
        self::OPENING => 'Opening stock',
        self::RECEIPT => 'Goods received',
        self::SUPPLIER_RETURN => 'Returned to supplier',
        self::TRANSFER_OUT => 'Moved out',
        self::TRANSFER_IN => 'Moved in',
        self::CONSUMPTION => 'Used in a dish',
        self::REVERSAL => 'Use reversed',
        self::WASTAGE => 'Wastage',
        self::ADJUSTMENT => 'Count adjustment',
        self::PRODUCTION_OUT => 'Used in production',
        self::PRODUCTION_IN => 'Produced',
        self::CUSTOMER_RETURN => 'Returned by a guest',
        self::OUTLET_OUT => 'Sent to another outlet',
        self::OUTLET_IN => 'Came from another outlet',
    ];

    protected $fillable = [
        'tenant_id', 'outlet_id', 'store_id', 'stock_item_id', 'batch_id', 'type',
        'qty', 'cost_rate', 'value_paise', 'balance_qty', 'reference_type', 'reference_id',
        'event_id', 'event_line', 'event_part', 'reason', 'user_id', 'business_date', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'cost_rate' => 'integer',
            'value_paise' => 'integer',
            'balance_qty' => 'integer',
            'batch_id' => 'integer',
            'business_date' => 'date',
            'occurred_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
