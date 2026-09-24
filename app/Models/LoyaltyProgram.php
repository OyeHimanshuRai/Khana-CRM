<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

/**
 * What a point is worth at one branch (SRS 15, 21).
 *
 * @property float $points_per_hundred
 * @property float $redeem_value
 * @property int $max_redeem_percent
 */
class LoyaltyProgram extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id', 'is_active', 'name',
        'points_per_hundred', 'min_spend',
        'redeem_value', 'min_redeem_points', 'max_redeem_percent',
        'expiry_months',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'points_per_hundred' => 'decimal:2',
            'min_spend' => 'decimal:2',
            'redeem_value' => 'decimal:2',
            'min_redeem_points' => 'integer',
            'max_redeem_percent' => 'integer',
            'expiry_months' => 'integer',
        ];
    }

    /**
     * Points earned by spending this much.
     *
     * Rounded down, always. A programme that rounds up pays out slightly more
     * than it advertised on every single bill, which is nobody's intention
     * and is very hard to notice.
     */
    public function pointsFor(float $amount): int
    {
        if ($amount < (float) $this->min_spend) {
            return 0;
        }

        return (int) floor(($amount / 100) * (float) $this->points_per_hundred);
    }

    /** What these points are worth in money. */
    public function valueOf(int $points): float
    {
        return round($points * (float) $this->redeem_value, 2);
    }

    /**
     * The most of this bill points may pay for.
     *
     * The rule that keeps a loyalty programme from becoming a discount
     * scheme: a guest arriving with four thousand points still pays
     * something, or the restaurant has sold a free meal it budgeted as a
     * discount.
     */
    public function redeemCeiling(float $billTotal): float
    {
        return round($billTotal * ($this->max_redeem_percent / 100), 2);
    }

    /** The most points that may be spent against this bill. */
    public function maxPointsFor(float $billTotal, int $balance): int
    {
        if ((float) $this->redeem_value <= 0) {
            return 0;
        }

        $byMoney = (int) floor($this->redeemCeiling($billTotal) / (float) $this->redeem_value);

        return max(0, min($balance, $byMoney));
    }
}
