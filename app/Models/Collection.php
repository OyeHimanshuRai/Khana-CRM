<?php

namespace App\Models;

use App\Models\Concerns\HasUniqueSlug;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A curated collection, with its artwork in collection_media.
 *
 * Note the class name shadows Illuminate\Support\Collection: any file that
 * needs both must alias one of them.
 */
class Collection extends Model
{
    use HasUniqueSlug;

    protected $fillable = [
        'name', 'slug', 'short_description', 'description',
        'sort_order', 'is_featured', 'is_active',
        'meta_title', 'meta_description',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_featured' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function media(): HasMany
    {
        return $this->hasMany(CollectionMedia::class);
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
            ->orWhere('slug', 'like', "%{$term}%")
            ->orWhere('short_description', 'like', "%{$term}%")
            ->orWhere('description', 'like', "%{$term}%"));
    }

    /* ------------------------------------------------------------- media */

    /**
     * The media rows keyed by "device.position", so a slot can be looked up
     * without a query per cell.
     *
     * @return array<string, CollectionMedia>
     */
    public function mediaBySlot(): array
    {
        return $this->media
            ->keyBy(fn (CollectionMedia $piece) => $piece->device.'.'.$piece->position)
            ->all();
    }

    public function mediaAt(string $device, string $position): ?CollectionMedia
    {
        return $this->mediaBySlot()[$device.'.'.$position] ?? null;
    }

    /**
     * What the listing shows: the desktop thumbnail, then the desktop
     * banner, then whatever else is an image. Videos make no still without
     * ffmpeg, so they are not candidates.
     */
    public function thumbnailUrl(): ?string
    {
        foreach ([['desktop', 'thumbnail'], ['desktop', 'banner'], ['mobile', 'thumbnail']] as [$device, $position]) {
            $piece = $this->mediaAt($device, $position);

            if ($piece && ! $piece->isVideo() && $piece->url()) {
                return $piece->url();
            }
        }

        return $this->media->first(fn (CollectionMedia $p) => ! $p->isVideo() && $p->url())?->url();
    }

    /** "3 of 6" for the listing's media column. */
    public function mediaSummary(): string
    {
        return $this->media->count().' of '.count(CollectionMedia::slots());
    }

    protected static function slugFallback(): string
    {
        return 'collection';
    }
}
