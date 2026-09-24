<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One counted line on a stock adjustment.
 */
class StockAdjustmentItem extends Model
{
    protected $fillable = [
        'stock_adjustment_id', 'product_id', 'batch_id',
        'system_quantity', 'counted_quantity', 'difference', 'unit_cost', 'note',
    ];

    protected function casts(): array
    {
        return [
            'system_quantity' => 'decimal:3',
            'counted_quantity' => 'decimal:3',
            'difference' => 'decimal:3',
            'unit_cost' => 'decimal:4',
        ];
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(StockAdjustment::class, 'stock_adjustment_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /** Value of the correction: negative when stock was lost. */
    public function valueChange(): float
    {
        return (float) $this->difference * (float) $this->unit_cost;
    }
}
