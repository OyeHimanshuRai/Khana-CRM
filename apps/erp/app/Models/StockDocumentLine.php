<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockDocumentLine extends Model
{
    protected $fillable = ['stock_document_id', 'stock_item_id', 'batch_id', 'qty', 'expected_qty', 'counted_qty', 'value_paise', 'note'];

    protected function casts(): array
    {
        return [
            'batch_id' => 'integer',
            'qty' => 'integer',
            'expected_qty' => 'integer',
            'counted_qty' => 'integer',
            'value_paise' => 'integer',
        ];
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
