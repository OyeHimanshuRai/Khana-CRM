<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A GST slab.
 *
 * Invoice lines copy these numbers rather than pointing at the row, so
 * changing a slab never rewrites tax on paperwork already issued.
 */
class TaxRate extends Model
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
        'name', 'rate', 'cgst', 'sgst', 'igst', 'cess',
        'is_default', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:3',
            'cgst' => 'decimal:3',
            'sgst' => 'decimal:3',
            'igst' => 'decimal:3',
            'cess' => 'decimal:3',
            'is_default' => 'boolean',
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
            ->orWhere('rate', 'like', "%{$term}%"));
    }

    /* --------------------------------------------------------- behaviour */

    /**
     * Split a total tax amount into the components an invoice must print.
     *
     * @param  bool  $interState  true when the place of supply differs from
     *                            the shop's state, which is what decides
     *                            IGST versus CGST+SGST.
     * @return array{cgst: float, sgst: float, igst: float, cess: float}
     */
    public function split(float $taxableValue, bool $interState): array
    {
        $cess = $taxableValue * ((float) $this->cess) / 100;

        if ($interState) {
            return [
                'cgst' => 0.0,
                'sgst' => 0.0,
                'igst' => $taxableValue * ((float) $this->igst) / 100,
                'cess' => $cess,
            ];
        }

        return [
            'cgst' => $taxableValue * ((float) $this->cgst) / 100,
            'sgst' => $taxableValue * ((float) $this->sgst) / 100,
            'igst' => 0.0,
            'cess' => $cess,
        ];
    }

    /** "GST 18%" for a picker. */
    public function label(): string
    {
        return $this->name.' ('.rtrim(rtrim(number_format((float) $this->rate, 2), '0'), '.').'%)';
    }

    /**
     * The slab a new product falls into when nothing is chosen.
     */
    public static function default(): ?self
    {
        return static::query()->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->first();
    }
}
