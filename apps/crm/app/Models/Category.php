<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Category extends Model
{
    /*
     | Owned by a company (SS4, SS10).
     |
     | This table was global until self-serve signup made that a leak: a
     | restaurant that opened its own account found somebody else's menu in
     | its screens, and on its own guest QR menu. The company rather than the
     | branch, because a group's outlets share one menu - what varies per
     | branch is the price, the stock and whether a dish is listed, and all
     | three already live elsewhere. See the migration that added the column.
     */
    use BelongsToTenant;

    protected $fillable = [
        'name', 'slug', 'description', 'is_active', 'sort_order',
        'parent_id', 'kitchen_station_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /* --------------------------------------------------------- relations */

    /**
     * The section this one sits under, for a sub-category (§8).
     *
     * One level deep, and that is enforced by the form rather than by the
     * column: a card that reads "Main Course > Indian > Breads > Stuffed" is
     * a card nobody can scan on a phone.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    /**
     * Where everything in this section is cooked (§9).
     *
     * The usual way routing is set, because the answer is nearly always "the
     * whole section goes to the same place". A dish overrides it; see
     * KitchenRouter for the order the two are read in.
     */
    public function kitchenStation(): BelongsTo
    {
        return $this->belongsTo(KitchenStation::class, 'kitchen_station_id');
    }

    /* ------------------------------------------------------------ scopes */

    /** Top-level sections only - the headings a printed card would have. */
    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    /*
     | image_path is deliberately not fillable: the only way to set it is
     | through the controller's upload handler, never from validated form
     | input that a client could name.
     */

    /* ------------------------------------------------------------ scopes */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Free-text across the fields the toolbar searches. */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('name', 'like', "%{$term}%")
            ->orWhere('slug', 'like', "%{$term}%")
            ->orWhere('description', 'like', "%{$term}%"));
    }

    /* --------------------------------------------------------- accessors */

    /**
     * Public URL of the uploaded image, or null.
     *
     * Tolerant of a missing file on purpose: a row pointing at something
     * swept off disk should fall back to the placeholder rather than render
     * a broken image.
     */
    public function imageUrl(): ?string
    {
        if (blank($this->image_path)) {
            return null;
        }

        $disk = Storage::disk('public');

        return $disk->exists($this->image_path) ? $disk->url($this->image_path) : null;
    }

    /** First letters of the name, for the no-image placeholder. */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->filter()
            ->take(2)
            ->map(fn (string $part) => Str::substr($part, 0, 1))
            ->implode('');
    }

    /* ------------------------------------------------------------- slugs */

    /**
     * A URL-safe slug that is not already taken.
     *
     * Falls back to appending a counter rather than failing the save, so a
     * second "Rings" becomes "rings-2" instead of a unique-constraint error
     * the user has to decipher.
     */
    public static function uniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug($source) ?: 'category';
        $slug = $base;
        $suffix = 2;

        while (
            static::where('slug', $slug)
                ->when($ignoreId, fn (Builder $q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
