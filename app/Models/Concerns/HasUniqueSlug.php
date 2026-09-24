<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Slug generation for the content models.
 *
 * Lifted out of Category, Service and Blog, which each had the same method
 * with a different fallback word - the only thing that actually varied.
 */
trait HasUniqueSlug
{
    /**
     * A URL-safe slug that is not already taken.
     *
     * Appends a counter rather than failing the save, so a second "Repairs"
     * becomes "repairs-2" instead of a unique-constraint error the user has
     * to decipher.
     */
    public static function uniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug($source) ?: static::slugFallback();
        $slug = $base;
        $suffix = 2;

        // Soft-deleted rows still hold their slug against the unique index,
        // so they have to count as taken - otherwise restoring one collides.
        $taken = in_array(SoftDeletes::class, class_uses_recursive(static::class), true)
            ? fn () => static::query()->withTrashed()
            : fn () => static::query();

        while (
            $taken()
                ->where('slug', $slug)
                ->when($ignoreId, fn (Builder $q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    /** Used when the source slugs down to nothing - "!!!", say. */
    protected static function slugFallback(): string
    {
        return Str::slug(class_basename(static::class)) ?: 'item';
    }
}
