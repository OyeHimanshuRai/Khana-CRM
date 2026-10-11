<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderLine extends Model
{
    protected $fillable = ['purchase_order_id', 'stock_item_id', 'qty', 'entered_qty', 'entered_unit', 'cost_rate', 'amount_paise', 'received_qty'];

    protected function casts(): array
    {
        return ['qty' => 'integer', 'cost_rate' => 'integer', 'amount_paise' => 'integer', 'received_qty' => 'integer'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }

    public function outstanding(): int
    {
        return max(0, $this->qty - $this->received_qty);
    }
}
