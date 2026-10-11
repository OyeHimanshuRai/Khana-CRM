<?php

namespace App\Models;

use App\Models\Concerns\LandingContent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A kind of business the product suits (§19).
 *
 * Fine dining, QSR, cafe, cloud kitchen, bakery, bar. The cheapest section on
 * the landing page and one of the most useful: a reader recognising their own
 * format is most of the sale, and a list of nine formats says "this was built
 * for restaurants" faster than a paragraph claiming it.
 *
 * Icon rather than image - see the migration.
 */
class OutletType extends Model
{
    use LandingContent;

    protected $fillable = [
        'name', 'blurb', 'icon', 'sort_order', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('name', 'like', "%{$term}%")
            ->orWhere('blurb', 'like', "%{$term}%"));
    }

    /**
     * The icon to draw, guaranteed to exist.
     *
     * config/icons.php is a fixed map and the component renders its markup
     * unescaped, so an unknown name must never reach it. A row whose icon was
     * renamed out of the set falls back rather than rendering nothing.
     */
    public function iconName(): string
    {
        return array_key_exists((string) $this->icon, config('icons', []))
            ? (string) $this->icon
            : 'cart';
    }
}
