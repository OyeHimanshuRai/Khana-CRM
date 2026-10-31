<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A logged-in customer's cart, one per customer per shop.
 *
 * Reused indefinitely - there is no "status"; CartService::clear() deletes
 * the item rows once an Order is placed from them. Read via
 * App\Services\CartService, not directly, so guest (session-backed) and
 * logged-in (DB-backed) carts stay behind one interface.
 */
class Cart extends Model
{
    protected $fillable = ['shop_id', 'customer_id'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }
}
