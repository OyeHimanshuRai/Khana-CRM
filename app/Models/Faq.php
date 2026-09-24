<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class Faq extends Model
{
    protected $fillable = [
        'question', 'answer', 'category', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
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
            ->where('question', 'like', "%{$term}%")
            ->orWhere('answer', 'like', "%{$term}%")
            ->orWhere('category', 'like', "%{$term}%"));
    }

    /* -------------------------------------------------------- categories */

    /**
     * The category labels already in use, for the filter and the form's
     * autocomplete.
     *
     * Derived from the rows rather than kept in a table of its own: these
     * are a handful of free-text labels, and a list that cleans itself up
     * when the last FAQ using a label goes is the point.
     *
     * @return Collection<int, string>
     */
    public static function categories(): Collection
    {
        return static::query()
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');
    }

    public function categoryLabel(): string
    {
        return filled($this->category) ? $this->category : 'Uncategorised';
    }
}
