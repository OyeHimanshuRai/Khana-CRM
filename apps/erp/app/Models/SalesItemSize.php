<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A size of a sales item: Half, Full, 300 ml. */
class SalesItemSize extends Model
{
    protected $fillable = ['sales_item_id', 'ref', 'name', 'sku', 'barcode', 'price_paise', 'is_default', 'is_active'];

    protected function casts(): array
    {
        return ['price_paise' => 'integer', 'is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(SalesItem::class, 'sales_item_id');
    }
}
