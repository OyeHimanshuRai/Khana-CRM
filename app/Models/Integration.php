<?php

namespace App\Models;

use App\Models\Concerns\LandingContent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Something the product plugs into (§19).
 *
 * The logo is the content here. Nobody reads the name of a payment gateway
 * they already use - they recognise the mark - so a row with no image still
 * renders, as a plain wordmark, rather than being dropped.
 */
class Integration extends Model
{
    use LandingContent;

    public const IMAGE_DIR = 'integrations';

    protected $fillable = [
        'name', 'category', 'url', 'sort_order', 'is_active',
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
            ->orWhere('category', 'like', "%{$term}%"));
    }

    /**
     * The categories in use, for the admin filter.
     *
     * Derived from the rows rather than from a list, the same way Faq does it:
     * a category exists because something is in it, and disappears when the
     * last row leaves.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    public static function categories(): \Illuminate\Support\Collection
    {
        return static::query()
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');
    }
}
