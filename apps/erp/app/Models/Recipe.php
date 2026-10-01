<?php

namespace App\Models;

use App\Models\Concerns\InOutlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What something is made of.
 *
 * Exactly one of three things has a recipe: a dish (a sales item, or one
 * size of it), an add-on, or a prepared stock item. The ids that do not
 * apply are 0. Written through App\Services\Recipes, which also tells CRM
 * when a recipe changes what a dish costs or whether it can be made.
 */
class Recipe extends Model
{
    use InOutlet;

    protected $fillable = [
        'tenant_id', 'outlet_id', 'sales_item_id', 'sales_item_size_id', 'sales_addon_id', 'stock_item_id',
        'yield_qty', 'notes', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'sales_item_id' => 'integer',
            'sales_item_size_id' => 'integer',
            'sales_addon_id' => 'integer',
            'stock_item_id' => 'integer',
            'yield_qty' => 'integer',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RecipeLine::class)->orderBy('id');
    }

    public function salesItem(): BelongsTo
    {
        return $this->belongsTo(SalesItem::class);
    }

    public function size(): BelongsTo
    {
        return $this->belongsTo(SalesItemSize::class, 'sales_item_size_id');
    }

    public function addon(): BelongsTo
    {
        return $this->belongsTo(SalesAddon::class, 'sales_addon_id');
    }

    /** The prepared item this recipe makes. */
    public function makes(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }

    public function isPrepared(): bool
    {
        return $this->stock_item_id > 0;
    }
}
