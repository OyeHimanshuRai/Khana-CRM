<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** How an item is bought and counted: "1 kg bag", "crate of 24". */
class StockItemPack extends Model
{
    protected $fillable = ['stock_item_id', 'name', 'qty'];

    protected function casts(): array
    {
        return ['qty' => 'integer'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }
}
