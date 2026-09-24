<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One lot of one product, as received by one shop.
 */
class Batch extends Model
{
    use BelongsToShop;

    /** Days before expiry at which a batch starts being flagged. */
    public const NEAR_EXPIRY_DAYS = 90;

    protected $fillable = [
        'shop_id', 'product_id', 'batch_no',
        'mfg_date', 'expiry_date',
        'purchase_price', 'mrp', 'selling_price',
        'supplier_batch_ref', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'mfg_date' => 'date',
            'expiry_date' => 'date',
            'purchase_price' => 'decimal:4',
            'mrp' => 'decimal:4',
            'selling_price' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    /* --------------------------------------------------------- relations */

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(ProductStock::class);
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
            ->where('batch_no', 'like', "%{$term}%")
            ->orWhere('supplier_batch_ref', 'like', "%{$term}%")
            ->orWhereHas('product', fn (Builder $p) => $p
                ->where('name', 'like', "%{$term}%")
                ->orWhere('sku', 'like', "%{$term}%")));
    }

    /** Already past its date. */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereNotNull('expiry_date')->whereDate('expiry_date', '<', today());
    }

    /** Not expired yet, but close enough to act on. */
    public function scopeNearExpiry(Builder $query, ?int $days = null): Builder
    {
        $days ??= self::NEAR_EXPIRY_DAYS;

        return $query->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '>=', today())
            ->whereDate('expiry_date', '<=', today()->addDays($days));
    }

    /**
     * Oldest expiry first, undated lots last.
     *
     * The picking order for anything perishable: sell what dies first.
     */
    public function scopeFefo(Builder $query): Builder
    {
        return $query->orderByRaw('expiry_date IS NULL, expiry_date ASC')->orderBy('id');
    }

    /* --------------------------------------------------------- behaviour */

    public function isExpired(): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->isBefore(today());
    }

    public function isNearExpiry(?int $days = null): bool
    {
        $remaining = $this->daysToExpiry();

        return $remaining !== null
            && $remaining >= 0
            && $remaining <= ($days ?? self::NEAR_EXPIRY_DAYS);
    }

    /** Negative once it has passed. */
    public function daysToExpiry(): ?int
    {
        return $this->expiry_date === null
            ? null
            : (int) today()->diffInDays($this->expiry_date, false);
    }

    /** "12 Mar 2027", or an em dash. */
    public function expiryLabel(): string
    {
        return $this->expiry_date?->format('d M Y') ?? '—';
    }

    /** Quantity of this lot on hand across the shop's warehouses. */
    public function quantity(): float
    {
        return (float) $this->stocks()->sum('quantity');
    }

    /** Where the badge should sit: danger, warning or nothing. */
    public function expiryTone(): ?string
    {
        return match (true) {
            $this->isExpired() => 'danger',
            $this->isNearExpiry() => 'warning',
            default => null,
        };
    }

    /**
     * Batches of one product that may still be sold, best-before first.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, self>
     */
    public static function sellable(int $productId, bool $blockExpired = true)
    {
        return static::query()
            ->active()
            ->where('product_id', $productId)
            ->when($blockExpired, fn (Builder $q) => $q->where(
                fn (Builder $inner) => $inner
                    ->whereNull('expiry_date')
                    ->orWhereDate('expiry_date', '>=', today())
            ))
            ->fefo()
            ->get();
    }
}
