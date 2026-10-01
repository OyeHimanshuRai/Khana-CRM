<?php

namespace App\Models;

use App\Models\Concerns\InOutlet;
use App\Support\Units;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How much of an item one store holds, per batch.
 *
 * Written only by App\Services\StockService, and always together with the
 * movement that explains the change - which is what keeps a level equal to
 * the sum of its movements.
 */
class StockLevel extends Model
{
    use InOutlet;

    protected $fillable = ['tenant_id', 'outlet_id', 'store_id', 'stock_item_id', 'batch_id', 'qty', 'cost_rate'];

    protected function casts(): array
    {
        return ['qty' => 'integer', 'cost_rate' => 'integer', 'batch_id' => 'integer'];
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

    public function valuePaise(): int
    {
        return Units::value($this->qty, $this->cost_rate);
    }
}
