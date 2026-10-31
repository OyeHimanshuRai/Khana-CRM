<?php

namespace App\Models;

use App\Models\Concerns\HasUniqueSlug;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class Blog extends Model
{
    use HasUniqueSlug;

    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const INACTIVE = 'inactive';

    /** The three states a post can be in, and how each reads. */
    public const STATUSES = [
        self::DRAFT => 'Draft',
        self::PUBLISHED => 'Published',
        self::INACTIVE => 'Inactive',
    ];

    protected $fillable = [
        'title', 'slug', 'short_description', 'content',
        'author_id', 'author_name', 'category', 'tags',
        'published_at', 'seo_title', 'seo_description',
        'status', 'is_featured',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'published_at' => 'datetime',
            'is_featured' => 'boolean',
        ];
    }

    /*
     | featured_image_path is deliberately not fillable: it is only ever set
     | by the controller's upload handler, never from form input.
     */

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /* ------------------------------------------------------------ scopes */

    /**
     * Live on the public site: published, and not dated into the future.
     *
     * A future published_at is how a post is scheduled, so "published" on
     * its own is not enough to mean visible.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', self::PUBLISHED)
            ->where(fn (Builder $q) => $q
                ->whereNull('published_at')
                ->orWhere('published_at', '<=', now()));
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('title', 'like', "%{$term}%")
            ->orWhere('slug', 'like', "%{$term}%")
            ->orWhere('short_description', 'like', "%{$term}%")
            ->orWhere('content', 'like', "%{$term}%")
            ->orWhere('author_name', 'like', "%{$term}%")
            ->orWhere('category', 'like', "%{$term}%"));
    }

    /* --------------------------------------------------------- accessors */

    /**
     * Public URL of the featured image, or null.
     *
     * Tolerant of a missing file: a row pointing at something swept off disk
     * falls back to the placeholder rather than a broken image.
     */
    public function imageUrl(): ?string
    {
        if (blank($this->featured_image_path)) {
            return null;
        }

        $disk = Storage::disk('public');

        return $disk->exists($this->featured_image_path)
            ? $disk->url($this->featured_image_path)
            : null;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /** Badge tone for the listing. */
    public function statusTone(): string
    {
        return match ($this->status) {
            self::PUBLISHED => $this->isScheduled() ? 'badge-warning' : 'badge-success',
            self::INACTIVE => 'badge-danger',
            default => 'badge',
        };
    }

    /** Published, but dated into the future - not visible yet. */
    public function isScheduled(): bool
    {
        return $this->status === self::PUBLISHED
            && $this->published_at !== null
            && $this->published_at->isFuture();
    }

    public function authorLabel(): string
    {
        // The relation first, then the name captured when it was written -
        // which is what survives the account being deleted.
        return $this->author?->name ?? ($this->author_name ?: 'Unknown');
    }

    /** @return Collection<int, string> */
    public function tagList(): Collection
    {
        return collect($this->tags ?? [])->filter()->values();
    }

    /**
     * Turn a comma separated field into the stored array.
     *
     * @return array<int, string>
     */
    public static function parseTags(?string $input): array
    {
        return collect(explode(',', (string) $input))
            ->map(fn (string $tag) => trim($tag))
            ->filter()
            ->unique()
            ->take(20)
            ->values()
            ->all();
    }

    /* -------------------------------------------------------- categories */

    /**
     * The category labels already in use, for the filter and the form.
     *
     * Derived from the rows rather than a table of its own, so the list
     * cleans itself up when the last post using a label goes.
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

    /** Every author who has written something, for the filter. */
    public static function authors(): EloquentCollection
    {
        return User::query()
            ->whereIn('id', static::query()->whereNotNull('author_id')->distinct()->pluck('author_id'))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    protected static function slugFallback(): string
    {
        return 'post';
    }
}
