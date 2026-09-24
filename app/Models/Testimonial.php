<?php

namespace App\Models;

use App\Models\Concerns\LandingContent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * What a customer said about the product (§19).
 *
 * Not `Feedback`. That model is what a *guest* thought of a *restaurant*, it
 * carries BelongsToShop, and putting it on this page would print one
 * customer's reviews on the website another customer is reading. These are
 * quotes the platform collected about itself, and they are platform-level.
 */
class Testimonial extends Model
{
    use LandingContent;

    /**
     * The image column the trait reads. Named on every one of these models so
     * the trait's `$this->image_path ?? null` is not a guess.
     */
    public const IMAGE_DIR = 'testimonials';

    protected $fillable = [
        'quote', 'author_name', 'author_role', 'company',
        'rating', 'sort_order', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /* ------------------------------------------------------------ scopes */

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('quote', 'like', "%{$term}%")
            ->orWhere('author_name', 'like', "%{$term}%")
            ->orWhere('company', 'like', "%{$term}%"));
    }

    /* --------------------------------------------------------- behaviour */

    /**
     * "Owner, Burgerama" — the line under the name.
     *
     * Both halves are optional, so this returns null rather than a stray comma
     * when neither was filled in.
     */
    public function attribution(): ?string
    {
        $parts = array_filter([$this->author_role, $this->company]);

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * Initials, for a quote with no photograph.
     *
     * Most testimonials arrive without one - see the migration - so this is
     * the normal case rather than the fallback.
     */
    public function initials(): string
    {
        $words = preg_split('/\s+/', trim((string) $this->author_name)) ?: [];

        return strtoupper(mb_substr($words[0] ?? '?', 0, 1).mb_substr($words[1] ?? '', 0, 1));
    }

    /** Is the rating a number this can actually draw stars for? */
    public function hasRating(): bool
    {
        return $this->rating !== null && $this->rating >= 1 && $this->rating <= 5;
    }
}
