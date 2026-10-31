<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One dish's price inside a price list (SRS 8, 16).
 *
 * A flat price or a percentage off, never both - see the migration.
 */
class PriceListItem extends Model
{
    protected $fillable = [
        'price_list_id', 'product_id', 'product_variant_id',
        'price', 'discount_percent',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'discount_percent' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<PriceList, $this> */
    public function list(): BelongsTo
    {
        return $this->belongsTo(PriceList::class, 'price_list_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * What this row makes the dish cost, given its normal price.
     *
     * Never below zero, and never above what it already was: a "discount"
     * that raised a price would be the one bug on this screen nobody would
     * think to look for.
     */
    public function apply(float $normal): float
    {
        if ($this->price !== null) {
            return max(0.0, round((float) $this->price, 2));
        }

        if ($this->discount_percent !== null) {
            $off = $normal * ((float) $this->discount_percent / 100);

            return max(0.0, round($normal - $off, 2));
        }

        return $normal;
    }
}
