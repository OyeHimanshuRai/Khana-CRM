<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line coming back.
 *
 * `condition` is the field that matters: only resalable goods go back on the
 * shelf. Restocking a burst bag would make the next count wrong and the loss
 * invisible, which is the opposite of what a returns document is for.
 */
class SalesReturnItem extends Model
{
    public const RESALABLE = 'resalable';

    public const DAMAGED = 'damaged';

    public const EXPIRED = 'expired';

    /** @var array<string, array{label: string, restock: bool}> */
    public const CONDITIONS = [
        self::RESALABLE => ['label' => 'Back on the shelf', 'restock' => true],
        self::DAMAGED => ['label' => 'Damaged — written off', 'restock' => false],
        self::EXPIRED => ['label' => 'Expired — written off', 'restock' => false],
    ];

    protected $fillable = [
        'sales_return_id', 'invoice_item_id', 'product_id', 'batch_id',
        'product_name', 'sku', 'unit_code', 'batch_no',
        'quantity', 'condition',
        'unit_price', 'taxable_value', 'tax_rate', 'tax_amount', 'line_total',
        'unit_cost', 'note',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:4',
            'taxable_value' => 'decimal:2',
            'tax_rate' => 'decimal:3',
            'tax_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
            'unit_cost' => 'decimal:4',
        ];
    }

    /* --------------------------------------------------------- relations */

    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class, 'sales_return_id');
    }

    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class);
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

    /** Whether this line's goods go back into sellable stock. */
    public function isResalable(): bool
    {
        return (self::CONDITIONS[$this->condition]['restock'] ?? false) === true;
    }

    public function conditionLabel(): string
    {
        return self::CONDITIONS[$this->condition]['label'] ?? ucfirst((string) $this->condition);
    }
}
