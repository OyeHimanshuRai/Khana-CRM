<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Support\CurrentShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * A place in the building where food is made (§9).
 *
 * Shop-scoped through BelongsToShop, so every read is already narrowed to the
 * branch the request is working in.
 */
class KitchenStation extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id', 'name', 'code', 'description',
        'is_default', 'prep_minutes', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'prep_minutes' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /* --------------------------------------------------------- relations */

    /** Categories routed here wholesale. */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /** Dishes routed here individually, overriding their category. */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** KOT lines this station has cooked or is cooking. */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
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
            ->orWhere('code', 'like', "%{$term}%")
            ->orWhere('description', 'like', "%{$term}%"));
    }

    /* --------------------------------------------------------- behaviour */

    /**
     * The station that takes anything nobody routed.
     *
     * Falls back to the first active station when no default is marked,
     * because a shop with stations and no default still has to cook. Null
     * only when the branch has no stations at all, which is the one case the
     * router treats as "this shop does not work in stations" rather than as
     * a misconfiguration.
     */
    public static function defaultFor(?int $shopId = null): ?self
    {
        $shopId ??= CurrentShop::id();

        return static::query()
            ->when($shopId, fn (Builder $q) => $q->where('shop_id', $shopId))
            ->active()
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();
    }

    /**
     * Make this the shop's default, and no other.
     *
     * Done in a transaction and in SQL rather than by loading the siblings,
     * because two people saving two station forms at once would otherwise
     * leave a branch with two defaults or none. A partial unique index would
     * express this better but is not portable across the databases this runs
     * on, so the invariant is held here - in one method that every writer
     * goes through.
     */
    public function makeDefault(): void
    {
        DB::transaction(function () {
            static::withoutGlobalScopes()
                ->where('shop_id', $this->shop_id)
                ->whereKeyNot($this->getKey())
                ->update(['is_default' => false]);

            $this->forceFill(['is_default' => true])->save();
        });
    }

    /** How long this station is given before a ticket is shown as late. */
    public function prepMinutes(): int
    {
        return max(1, (int) ($this->prep_minutes ?: 15));
    }
}
