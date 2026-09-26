<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line on a goods receipt.
 *
 * Carries the batch details captured at the door - number, manufacture and
 * expiry - because that is the only moment they are knowable, and because
 * a lot received without them can never be sold FEFO afterwards.
 */
class GoodsReceiptItem extends Model
{
    protected $fillable = [
        'goods_receipt_id', 'product_id', 'batch_id',
        'product_name', 'sku', 'unit_code',
        'batch_no', 'mfg_date', 'expiry_date',
        'quantity', 'free_quantity', 'unit_cost',
        'discount_percent', 'discount_amount', 'taxable_value',
        'tax_rate', 'cgst_amount', 'sgst_amount', 'igst_amount',
        'line_total', 'landed_cost', 'mrp', 'selling_price', 'note',
    ];

    /* returned_quantity is maintained by PurchaseReturnService, never mass-assigned. */

    protected function casts(): array
    {
        return [
            'mfg_date' => 'date',
            'expiry_date' => 'date',
            'quantity' => 'decimal:3',
            'free_quantity' => 'decimal:3',
            'returned_quantity' => 'decimal:3',
            'unit_cost' => 'decimal:4',
            'discount_percent' => 'decimal:3',
            'discount_amount' => 'decimal:2',
            'taxable_value' => 'decimal:2',
            'tax_rate' => 'decimal:3',
            'cgst_amount' => 'decimal:2',
            'sgst_amount' => 'decimal:2',
            'igst_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
            'landed_cost' => 'decimal:4',
            'mrp' => 'decimal:4',
            'selling_price' => 'decimal:4',
        ];
    }

    /* --------------------------------------------------------- relations */

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /* --------------------------------------------------------- behaviour */

    /**
     * Everything that goes on the shelf, paid for or not.
     *
     * Free stock is real stock: it has to be counted, and it drags the
     * weighted average cost down, which is exactly right - the shop did get
     * more units for the same money.
     */
    public function totalQuantity(): float
    {
        return (float) $this->quantity + (float) $this->free_quantity;
    }

    public function taxAmount(): float
    {
        return (float) $this->cgst_amount + (float) $this->sgst_amount + (float) $this->igst_amount;
    }

    /** How much of this line could still go back to the supplier. */
    public function returnableQuantity(): float
    {
        return max(0.0, $this->totalQuantity() - (float) $this->returned_quantity);
    }

    public function isFullyReturned(): bool
    {
        return $this->returnableQuantity() <= 0.0005;
    }

    /**
     * The margin this line implies at its intended selling price.
     *
     * Shown while receiving so a buyer notices immediately when a supplier's
     * price rise has quietly made a product unprofitable.
     */
    public function marginPercent(): ?float
    {
        $selling = (float) $this->selling_price;
        $cost = (float) $this->landed_cost ?: (float) $this->unit_cost;

        if ($selling <= 0 || $cost <= 0) {
            return null;
        }

        return (($selling - $cost) / $selling) * 100;
    }
}
