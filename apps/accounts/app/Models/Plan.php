<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Khana\Kit\Support\Money;

/**
 * Something the platform sells: CRM, ERP, or both as the Suite. Priced per
 * outlet, in paise.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $blurb
 * @property list<string> $products
 * @property int $monthly_price_paise
 * @property int|null $yearly_price_paise
 * @property int $trial_days
 * @property array<string, int|null> $limits
 * @property bool $is_active
 */
class Plan extends Model
{
    public const MONTHLY = 'monthly';

    public const YEARLY = 'yearly';

    public const PERIODS = [self::MONTHLY, self::YEARLY];

    protected $fillable = [
        'code', 'name', 'blurb', 'products',
        'monthly_price_paise', 'yearly_price_paise', 'trial_days', 'limits',
        'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'products' => 'array',
            'limits' => 'array',
            'monthly_price_paise' => 'integer',
            'yearly_price_paise' => 'integer',
            'trial_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('id');
    }

    public function covers(string $product): bool
    {
        return in_array($product, $this->products, true);
    }

    public function isSuite(): bool
    {
        return $this->covers('crm') && $this->covers('erp');
    }

    /** A year that was never priced is twelve months. */
    public function pricePaise(string $period): int
    {
        return $period === self::YEARLY
            ? ($this->yearly_price_paise ?? $this->monthly_price_paise * 12)
            : $this->monthly_price_paise;
    }

    public function price(string $period = self::MONTHLY): Money
    {
        return Money::paise($this->pricePaise($period));
    }

    /** @return list<string> */
    public function productLabels(): array
    {
        return array_map(fn (string $p) => strtoupper($p), $this->products);
    }
}
