<?php

namespace App\Models;

use App\Models\Concerns\LandingContent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A screenshot of the product (§19).
 *
 * Deliberately not a Slider row - see the migration. A slider slot is a hero
 * banner with a layout and a link; this is a picture of a screen with a
 * sentence under it.
 *
 * A row whose image is missing is skipped by the landing page rather than
 * rendered empty: a screenshot section is its screenshots.
 */
class Showcase extends Model
{
    use LandingContent;

    public const IMAGE_DIR = 'showcases';

    protected $fillable = [
        'title', 'caption', 'sort_order', 'is_active',
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
            ->where('title', 'like', "%{$term}%")
            ->orWhere('caption', 'like', "%{$term}%"));
    }
}
