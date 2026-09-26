<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One size of one dish: Half, Full, Large, 6".
 *
 * Not shop-scoped, and deliberately so: a variant belongs to a product, the
 * product is global catalogue, and the per-shop overrides live on
 * `product_shop` exactly as they do for the base price. Adding a second
 * scoping rule here would mean two answers to "what does a Large cost".
 */
class ProductVariant extends Model
{
    protected $fillable = [
        'product_id', 'name', 'sku', 'price', 'mrp',
        'is_default', 'is_available', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:4',
            'mrp' => 'decimal:4',
            'is_default' => 'boolean',
            'is_available' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /* --------------------------------------------------------- relations */

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /* ------------------------------------------------------------ scopes */

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_available', true);
    }

    /* --------------------------------------------------------- behaviour */

    /** "Biryani — Full", for a KOT line or a bill. */
    public function fullName(): string
    {
        return trim(($this->product?->name ?? '').' — '.$this->name, ' —');
    }

    /**
     * Make this the size the menu opens on, demoting its siblings.
     *
     * Not in a transaction, and that is a considered difference from
     * Warehouse::makeDefault(): two rows briefly both flagged here shows the
     * customer menu a second size pre-selected for a few milliseconds.
     * Nobody is billed wrongly and nothing reconciles against it.
     */
    public function makeDefault(): void
    {
        static::query()
            ->where('product_id', $this->product_id)
            ->where('id', '!=', $this->id)
            ->update(['is_default' => false]);

        $this->forceFill(['is_default' => true])->save();
    }
}
