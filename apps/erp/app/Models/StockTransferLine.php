<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One batch of one item on a transfer: what left, what it was worth, and -
 * once the other outlet has opened the crate - how much of it arrived.
 */
class StockTransferLine extends Model
{
    protected $fillable = [
        'stock_transfer_id', 'stock_item_id', 'batch_id', 'batch_no', 'expiry_date', 'made_on',
        'qty', 'cost_rate', 'value_paise', 'received_qty',
    ];

    protected function casts(): array
    {
        return [
            'batch_id' => 'integer',
            'expiry_date' => 'date',
            'made_on' => 'date',
            'qty' => 'integer',
            'cost_rate' => 'integer',
            'value_paise' => 'integer',
            'received_qty' => 'integer',
        ];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }

    /** What left and did not arrive. Nothing, until it has been received. */
    public function shortQty(): int
    {
        return $this->received_qty === null ? 0 : $this->qty - $this->received_qty;
    }
}
