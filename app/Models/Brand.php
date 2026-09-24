<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUniqueSlug;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A product brand. Catalogue master, shared by every shop.
 */
class Brand extends Model
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
    use BelongsToTenant, HasUniqueSlug;

    protected $fillable = [
        'name', 'slug', 'description', 'manufacturer', 'website', 'is_active', 'sort_order',
    ];

    /*
     | logo_path is deliberately not fillable: the only way to set it is
     | through the controller's upload handler.
     */

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /* --------------------------------------------------------- relations */

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
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
            ->orWhere('manufacturer', 'like', "%{$term}%")
            ->orWhere('description', 'like', "%{$term}%"));
    }

    /* --------------------------------------------------------- accessors */

    public function logoUrl(): ?string
    {
        if (blank($this->logo_path)) {
            return null;
        }

        $disk = Storage::disk('public');

        return $disk->exists($this->logo_path) ? $disk->url($this->logo_path) : null;
    }

    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->filter()
            ->take(2)
            ->map(fn (string $part) => Str::substr($part, 0, 1))
            ->implode('');
    }

    protected static function slugFallback(): string
    {
        return 'brand';
    }
}
