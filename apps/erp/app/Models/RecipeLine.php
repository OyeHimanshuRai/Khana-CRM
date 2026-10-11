<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One ingredient of a recipe, and how much of it. */
class RecipeLine extends Model
{
    protected $fillable = ['recipe_id', 'stock_item_id', 'qty', 'entered_qty', 'entered_unit', 'is_optional'];

    protected function casts(): array
    {
        return ['qty' => 'integer', 'is_optional' => 'boolean'];
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }
}
