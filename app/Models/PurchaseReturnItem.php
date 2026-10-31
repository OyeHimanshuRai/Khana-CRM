<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line going back to a supplier.
 *
 * No `condition` column the way SalesReturnItem has one - see the
 * migration's docblock. Every line here leaves the shelf on approval.
 */
class PurchaseReturnItem extends Model
{
    protected $fillable = [
        'purchase_return_id', 'goods_receipt_item_id', 'product_id', 'batch_id',
        'product_name', 'sku', 'unit_code', 'batch_no',
        'quantity',
        'unit_cost', 'taxable_value', 'tax_rate', 'tax_amount', 'line_total',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_cost' => 'decimal:4',
            'taxable_value' => 'decimal:2',
            'tax_rate' => 'decimal:3',
            'tax_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    /* --------------------------------------------------------- relations */

    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    public function goodsReceiptItem(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }
}
