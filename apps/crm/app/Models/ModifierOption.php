<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One answer to a modifier question: "Thin crust", "Extra cheese", "No onion".
 *
 * Not shop-scoped itself - it hangs off a Modifier, which is. Scoping both
 * would mean two answers to the same question and a global scope that fires
 * on every eager load for nothing.
 */
class ModifierOption extends Model
{
    protected $fillable = [
        'modifier_id', 'name', 'price',
        'is_default', 'is_available', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:4',
            'is_default' => 'boolean',
            'is_available' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /* --------------------------------------------------------- relations */

    public function modifier(): BelongsTo
    {
        return $this->belongsTo(Modifier::class);
    }

    /* ------------------------------------------------------------ scopes */

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_available', true);
    }

    /* --------------------------------------------------------- behaviour */

    public function isFree(): bool
    {
        return (float) $this->price === 0.0;
    }

    /**
     * How the price reads next to the option.
     *
     * Free options say nothing rather than "₹0.00": a menu full of zeroes is
     * a menu nobody reads. A negative one keeps its sign, because "−₹20" is
     * the whole reason somebody ticks "no cheese".
     */
    public function priceLabel(): string
    {
        $price = (float) $this->price;

        if ($price === 0.0) {
            return '';
        }

        return ($price < 0 ? '− ₹' : '+ ₹').number_format(abs($price), 2);
    }
}
