<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ingredient in one dish (§10).
 *
 * Shop-scoped, because a recipe is a kitchen's own: the same chicken tikka is
 * made with more cream in one branch than another, and a chain that shared one
 * recipe row would cost both branches by whichever manager edited it last.
 */
class RecipeItem extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id', 'product_id', 'product_variant_id',
        'ingredient_id', 'quantity', 'note', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'sort_order' => 'integer',
        ];
    }

    /* --------------------------------------------------------- relations */

    /** The dish. */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** The size this row applies to, or null for every size. */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** What it is made of - itself a product, see the migration. */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'ingredient_id');
    }

    /* ------------------------------------------------------------ scopes */

    public function scopeForDish(Builder $query, int $productId): Builder
    {
        return $query->where('product_id', $productId);
    }

    /* --------------------------------------------------------- behaviour */

    /**
     * What this component costs, at what the shop last paid for it.
     *
     * Read from the ingredient's purchase price rather than from a stock
     * average, because a recipe screen is answering "what should this dish
     * cost" - a planning question - and an average that swings with every
     * delivery would make the same recipe show a different figure each week.
     * The *sold* cost is captured on the invoice line, which is a different
     * number and deliberately so.
     */
    public function cost(?int $shopId = null): float
    {
        $ingredient = $this->ingredient;

        if ($ingredient === null) {
            return 0.0;
        }

        return round((float) $this->quantity * $ingredient->purchasePriceFor($shopId), 4);
    }

    /** "0.15 KG" - the quantity as somebody in a kitchen would read it. */
    public function label(): string
    {
        $quantity = rtrim(rtrim(number_format((float) $this->quantity, 4, '.', ''), '0'), '.');

        return $quantity.' '.($this->ingredient?->unit?->code ?? '');
    }
}
