<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A storefront coupon: percent-off or fixed-amount-off, with an optional
 * minimum order amount and usage limits. See the migration for why v1 stops
 * there - no BOGO, no free shipping, no stacking.
 *
 * Carries BelongsToShop for free admin-side scoping under staff (`web`
 * guard) sessions. Storefront code runs under the `customer` guard, where
 * that scope is a no-op (see Order's docblock) - every storefront read of
 * this model goes through ::forShop() explicitly, never a bare query.
 */
class Coupon extends Model
{
    use BelongsToShop, SoftDeletes;

    public const PERCENT = 'percent';

    public const FIXED = 'fixed';

    protected $fillable = [
        'shop_id', 'code', 'description', 'type', 'value',
        'max_discount_amount', 'min_order_amount',
        'usage_limit', 'usage_limit_per_customer',
        'starts_at', 'expires_at', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'max_discount_amount' => 'decimal:2',
            'min_order_amount' => 'decimal:2',
            'usage_limit' => 'integer',
            'usage_limit_per_customer' => 'integer',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

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
            ->where('code', 'like', "%{$term}%")
            ->orWhere('description', 'like', "%{$term}%"));
    }

    public function isWithinWindow(): bool
    {
        $now = now();

        if ($this->starts_at && $now->lt($this->starts_at)) {
            return false;
        }

        return ! ($this->expires_at && $now->gt($this->expires_at));
    }

    public function typeLabel(): string
    {
        return $this->type === self::PERCENT ? 'Percent off' : 'Fixed amount off';
    }
}
