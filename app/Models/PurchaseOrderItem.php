<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line on a purchase order.
 */
class PurchaseOrderItem extends Model
{
    protected $fillable = [
        'purchase_order_id', 'product_id',
        'product_name', 'sku', 'unit_code',
        'quantity', 'received_quantity', 'unit_cost', 'tax_rate', 'line_total', 'note',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'received_quantity' => 'decimal:3',
            'unit_cost' => 'decimal:4',
            'tax_rate' => 'decimal:3',
            'line_total' => 'decimal:2',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Still to arrive.
     *
     * Never negative: a supplier who over-delivers has sent extra, not
     * created a negative shortfall, and the receipt records the real
     * quantity either way.
     */
    public function outstandingQuantity(): float
    {
        return max(0.0, (float) $this->quantity - (float) $this->received_quantity);
    }

    public function isFullyReceived(): bool
    {
        return $this->outstandingQuantity() <= 0.0005;
    }
}
