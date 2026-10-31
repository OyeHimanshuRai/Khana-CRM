<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line on a stock transfer.
 */
class StockTransferItem extends Model
{
    protected $fillable = [
        'stock_transfer_id', 'product_id', 'batch_id',
        'quantity', 'received_quantity', 'unit_cost', 'note',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'received_quantity' => 'decimal:3',
            'unit_cost' => 'decimal:4',
        ];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /**
     * How much went missing between the two warehouses.
     *
     * Null until the consignment is booked in - "not yet received" and
     * "received in full" are different facts and must not read alike.
     */
    public function shortfall(): ?float
    {
        if ($this->received_quantity === null) {
            return null;
        }

        return (float) $this->quantity - (float) $this->received_quantity;
    }
}
