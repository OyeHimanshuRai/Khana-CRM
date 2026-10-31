<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line on a sales invoice.
 *
 * Everything descriptive is copied rather than joined, so a reprint years
 * later shows what was actually sold under the name it was sold as.
 */
class InvoiceItem extends Model
{
    protected $fillable = [
        'invoice_id', 'product_id', 'batch_id',
        'product_name', 'sku', 'hsn_code', 'unit_code', 'batch_no', 'expiry_date',
        'quantity', 'mrp', 'unit_price',
        'discount_percent', 'discount_amount', 'taxable_value',
        'tax_rate', 'cgst_amount', 'sgst_amount', 'igst_amount', 'cess_amount',
        'line_total', 'unit_cost', 'returned_quantity',
    ];

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'quantity' => 'decimal:3',
            'mrp' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'discount_percent' => 'decimal:3',
            'discount_amount' => 'decimal:2',
            'taxable_value' => 'decimal:2',
            'tax_rate' => 'decimal:3',
            'cgst_amount' => 'decimal:2',
            'sgst_amount' => 'decimal:2',
            'igst_amount' => 'decimal:2',
            'cess_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
            'unit_cost' => 'decimal:4',
            'returned_quantity' => 'decimal:3',
        ];
    }

    /* --------------------------------------------------------- relations */

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
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

    /** Total tax on this line, however it was split. */
    public function taxAmount(): float
    {
        return (float) $this->cgst_amount
            + (float) $this->sgst_amount
            + (float) $this->igst_amount
            + (float) $this->cess_amount;
    }

    /** How much of this line could still come back. */
    public function returnableQuantity(): float
    {
        return max(0.0, (float) $this->quantity - (float) $this->returned_quantity);
    }

    public function isFullyReturned(): bool
    {
        return $this->returnableQuantity() <= 0.0005;
    }

    /** Gross profit on the line, at the cost captured when it was sold. */
    public function grossProfit(): float
    {
        return (float) $this->taxable_value - ((float) $this->quantity * (float) $this->unit_cost);
    }

    /** "12.5 KG" for a receipt. */
    public function quantityLabel(): string
    {
        $quantity = rtrim(rtrim(number_format((float) $this->quantity, 3, '.', ''), '0'), '.');

        return $quantity.' '.($this->unit_code ?? '');
    }
}
