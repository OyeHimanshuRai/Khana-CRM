<?php

namespace App\Models;

use App\Support\Units;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Khana\Kit\Tenancy\BelongsToTenant;

/**
 * Something the store buys, holds or cooks with: paneer, oil, a cola, a
 * gravy base made in-house.
 *
 * Shared by every outlet of a business - a chain buys the same paneer
 * everywhere - while how much there is belongs to each store (StockLevel).
 * Nothing a guest orders lives here: that is a sales item, and a recipe is
 * what joins the two.
 */
class StockItem extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'name', 'code', 'barcode', 'category', 'base_unit',
        'is_prepared', 'track_expiry', 'shelf_life_days', 'hsn', 'gst_rate_bp',
        'reorder_qty', 'last_cost_rate', 'is_active',
    ];

    /**
     * The table's own defaults, so a row just made reads the same as the
     * same row read back.
     */
    protected $attributes = [
        'is_prepared' => false,
        'track_expiry' => false,
        'gst_rate_bp' => 0,
        'reorder_qty' => 0,
        'last_cost_rate' => 0,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_prepared' => 'boolean',
            'track_expiry' => 'boolean',
            'is_active' => 'boolean',
            'gst_rate_bp' => 'integer',
            'reorder_qty' => 'integer',
            'last_cost_rate' => 'integer',
        ];
    }

    public function packs(): HasMany
    {
        return $this->hasMany(StockItemPack::class);
    }

    public function levels(): HasMany
    {
        return $this->hasMany(StockLevel::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The units a quantity of this item may be written in: the standard ones
     * for its base, then its own packs.
     *
     * @return array<string, string> option value => label
     */
    public function unitOptions(): array
    {
        $options = Units::optionsFor($this->base_unit);

        foreach ($this->packs as $pack) {
            $options['pack:'.$pack->id] = $pack->name;
        }

        return $options;
    }

    /** What somebody typed, in one of unitOptions(), as a stored quantity. */
    public function toBase(string|int|float $amount, string $unit): int
    {
        if (str_starts_with($unit, 'pack:')) {
            $pack = $this->packs->firstWhere('id', (int) substr($unit, 5));

            return Units::toBase($amount, 'pack', $this->base_unit, $pack?->qty);
        }

        return Units::toBase($amount, $unit, $this->base_unit);
    }

    public function show(int $qty): string
    {
        return Units::show($qty, $this->base_unit);
    }
}
