<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quantity on hand for one shop / warehouse / product / batch slot.
 *
 * A cache of stock_movements, kept in step by App\Services\StockService.
 * Nothing outside that service should write to it: every change to a
 * quantity has to leave a ledger row explaining itself, and going through
 * the service is what guarantees the pair stay together.
 */
class ProductStock extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id', 'warehouse_id', 'product_id', 'batch_id',
        'quantity', 'reserved', 'average_cost',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'reserved' => 'decimal:3',
            'average_cost' => 'decimal:4',
        ];
    }

    /* --------------------------------------------------------- relations */

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /* ------------------------------------------------------------ scopes */

    /** Anything actually sitting on a shelf. */
    public function scopeInStock(Builder $query): Builder
    {
        return $query->where('quantity', '>', 0);
    }

    public function scopeForWarehouse(Builder $query, ?int $warehouseId): Builder
    {
        return $warehouseId === null ? $query : $query->where('warehouse_id', $warehouseId);
    }

    /* --------------------------------------------------------- behaviour */

    /** On hand minus what is held for orders that are not yet picked. */
    public function available(): float
    {
        return (float) $this->quantity - (float) $this->reserved;
    }

    /**
     * The value of what is on hand, at weighted average cost.
     */
    public function value(): float
    {
        return (float) $this->quantity * (float) $this->average_cost;
    }
}
