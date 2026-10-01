<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoodsReceiptLine extends Model
{
    protected $fillable = ['goods_receipt_id', 'stock_item_id', 'batch_id', 'qty', 'entered_qty', 'entered_unit', 'cost_rate', 'net_paise', 'tax_paise'];

    protected function casts(): array
    {
        return ['qty' => 'integer', 'batch_id' => 'integer', 'cost_rate' => 'integer', 'net_paise' => 'integer', 'tax_paise' => 'integer'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }
}
