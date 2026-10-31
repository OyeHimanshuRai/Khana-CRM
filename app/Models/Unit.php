<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A unit of measure.
 *
 * The interesting column is allow_decimal: it is what lets the POS refuse
 * "2.5 sprayers" while accepting "2.5 kg of urea".
 */
class Unit extends Model
{
    protected $fillable = [
        'name', 'code', 'allow_decimal', 'precision', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'allow_decimal' => 'boolean',
            'precision' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /* --------------------------------------------------------- relations */

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /* ------------------------------------------------------------ scopes */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('name', 'like', "%{$term}%")
            ->orWhere('code', 'like', "%{$term}%"));
    }

    /* --------------------------------------------------------- behaviour */

    /**
     * Round a quantity the way this unit permits.
     *
     * Whole units round rather than truncate, so a 0.9 that arrived through
     * a rounding error becomes 1 instead of vanishing.
     */
    public function normalise(float $quantity): float
    {
        return $this->allow_decimal
            ? round($quantity, $this->precision)
            : round($quantity);
    }

    /** "12.500 kg" or "3 PCS", as the unit allows. */
    public function format(float $quantity): string
    {
        $decimals = $this->allow_decimal ? $this->precision : 0;

        return number_format($quantity, $decimals).' '.$this->code;
    }
}
