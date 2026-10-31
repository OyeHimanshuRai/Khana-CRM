<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A dining area within one outlet.
 *
 * Shop-scoped through BelongsToShop, so every read here is already narrowed
 * to the branch the request is working in.
 */
class Floor extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id', 'name', 'code', 'description', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /* --------------------------------------------------------- relations */

    public function tables(): HasMany
    {
        return $this->hasMany(RestaurantTable::class)
            ->orderBy('sort_order')
            ->orderBy('name');
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
            ->orWhere('code', 'like', "%{$term}%")
            ->orWhere('description', 'like', "%{$term}%"));
    }

    /* --------------------------------------------------------- behaviour */

    /**
     * How many covers this area seats when every table is free.
     *
     * Read off the active tables only. A floor whose tables are switched off
     * for the season seats nobody, and reporting its summer capacity would
     * make the dashboard's occupancy percentage a fiction.
     */
    public function capacity(): int
    {
        return (int) $this->tables()->where('is_active', true)->sum('capacity');
    }
}
