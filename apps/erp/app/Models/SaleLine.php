<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleLine extends Model
{
    protected $fillable = [
        'sale_id', 'ref', 'sales_item_id', 'sales_item_size_id', 'item_ref', 'variant_ref', 'option_refs',
        'name', 'qty', 'net_paise', 'tax_paise', 'kitchen_refs', 'cost_paise', 'returned_qty',
    ];

    protected function casts(): array
    {
        return [
            'option_refs' => 'array',
            'kitchen_refs' => 'array',
            'qty' => 'integer',
            'sales_item_id' => 'integer',
            'sales_item_size_id' => 'integer',
            'net_paise' => 'integer',
            'tax_paise' => 'integer',
            'cost_paise' => 'integer',
            'returned_qty' => 'integer',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function salesItem(): BelongsTo
    {
        return $this->belongsTo(SalesItem::class);
    }

    /** The kitchen lines this bill line settled. */
    public function kitchenLines(): HasMany
    {
        return $this->hasMany(KitchenLine::class);
    }
}
