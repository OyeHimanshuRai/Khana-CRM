<?php

namespace App\Models;

use App\Models\Concerns\InOutlet;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One delivery's worth of an item that goes off: its number and its expiry.
 *
 * Only items that track expiry have batches. Everything else sits in the
 * store as one undated lot (batch id 0 on the level).
 */
class Batch extends Model
{
    use InOutlet;

    protected $fillable = ['tenant_id', 'outlet_id', 'stock_item_id', 'batch_no', 'expiry_date', 'made_on'];

    protected function casts(): array
    {
        return ['expiry_date' => 'date', 'made_on' => 'date'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }

    /** Past its date on the given day. A batch with no date never expires. */
    public function isExpired(CarbonInterface|string|null $on = null): bool
    {
        if ($this->expiry_date === null) {
            return false;
        }

        $on = $on instanceof CarbonInterface ? $on->toDateString() : ($on ?? now()->toDateString());

        return $this->expiry_date->toDateString() < $on;
    }
}
