<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product line in a cart.
 *
 * Deliberately carries no price - see Cart's docblock. Always re-priced live
 * from Product::counterPriceFor() by whoever reads it.
 */
class CartItem extends Model
{
    protected $fillable = ['cart_id', 'product_id', 'quantity'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
        ];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
