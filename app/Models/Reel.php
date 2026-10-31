<?php

namespace App\Models;

use App\Models\Concerns\ParsesInstagramUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Reel extends Model
{
    use ParsesInstagramUrl;

    protected $fillable = [
        'title', 'description', 'reel_url', 'reel_id',
        'sort_order', 'published_at', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /*
     | thumbnail_path is deliberately not fillable: it is only ever set by
     | the controller's upload handler, never from form input.
     */

    public function shortcode(): ?string
    {
        // The stored code, or one recovered from the URL for a row saved
        // before the column existed.
        return $this->reel_id ?: static::shortcodeFrom($this->reel_url);
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
            ->where('title', 'like', "%{$term}%")
            ->orWhere('description', 'like', "%{$term}%")
            ->orWhere('reel_url', 'like', "%{$term}%")
            ->orWhere('reel_id', 'like', "%{$term}%"));
    }

    /* --------------------------------------------------------- accessors */

    /**
     * Public URL of the uploaded thumbnail, or null.
     *
     * Tolerant of a missing file: the listing shows a placeholder rather
     * than a broken image.
     */
    public function thumbnailUrl(): ?string
    {
        if (blank($this->thumbnail_path)) {
            return null;
        }

        $disk = Storage::disk('public');

        return $disk->exists($this->thumbnail_path) ? $disk->url($this->thumbnail_path) : null;
    }

    /** The next free position, so a new reel lands at the end. */
    public static function nextSortOrder(): int
    {
        return (int) static::max('sort_order') + 1;
    }
}
