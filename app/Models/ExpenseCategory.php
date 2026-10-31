<?php

namespace App\Models;

use App\Support\CurrentShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * How a shop groups its spending.
 *
 * Deliberately NOT using BelongsToShop: a null shop_id means "available
 * everywhere", which the standard tenant scope would filter out. The scope
 * below understands both cases.
 */
class ExpenseCategory extends Model
{
    protected $fillable = ['shop_id', 'name', 'description', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /**
     * Categories this shop may use: its own, plus the shared ones.
     */
    public function scopeUsable(Builder $query, ?int $shopId = null): Builder
    {
        $shopId ??= CurrentShop::id();
        $accessible = CurrentShop::accessibleIds();

        return $query
            ->where('is_active', true)
            ->where(function (Builder $q) use ($shopId, $accessible) {
                $q->whereNull('shop_id');

                if ($shopId !== null) {
                    $q->orWhere('shop_id', $shopId);
                } elseif ($accessible !== []) {
                    $q->orWhereIn('shop_id', $accessible);
                }
            })
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    /** Shared across every shop rather than belonging to one. */
    public function isShared(): bool
    {
        return $this->shop_id === null;
    }
}
