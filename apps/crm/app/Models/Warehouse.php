<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Support\CurrentShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * A storage location inside one shop.
 */
class Warehouse extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id', 'name', 'code', 'address', 'city', 'phone',
        'is_default', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /* --------------------------------------------------------- relations */

    public function stocks(): HasMany
    {
        return $this->hasMany(ProductStock::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
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
            ->orWhere('city', 'like', "%{$term}%")
            ->orWhere('address', 'like', "%{$term}%"));
    }

    /* --------------------------------------------------------- behaviour */

    /**
     * Make this the shop's default, demoting whichever one held the flag.
     *
     * In a transaction because the two writes must not be observable apart -
     * a moment with two defaults would send the same receipt's stock to two
     * different places.
     */
    public function makeDefault(): void
    {
        DB::transaction(function () {
            static::allShops()
                ->where('shop_id', $this->shop_id)
                ->where('id', '!=', $this->id)
                ->update(['is_default' => false]);

            $this->forceFill(['is_default' => true])->save();
        });
    }

    /**
     * Where stock lands in a given shop when nothing is chosen.
     *
     * Creates one on first use rather than returning null: a shop that has
     * never thought about locations still has to be able to receive goods,
     * and failing a goods receipt over a missing warehouse would be a poor
     * way to teach the concept.
     */
    public static function defaultFor(?int $shopId = null): ?self
    {
        $shopId ??= CurrentShop::id();

        if ($shopId === null) {
            return null;
        }

        $existing = static::allShops()
            ->where('shop_id', $shopId)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();

        if ($existing) {
            return $existing;
        }

        return static::withoutEvents(fn () => static::query()->create([
            'shop_id' => $shopId,
            'name' => 'Main Store',
            'code' => 'MAIN',
            'is_default' => true,
            'is_active' => true,
        ]));
    }
}
