<?php

namespace App\Models;

use App\Models\Concerns\HasUniqueSlug;
use App\Support\Modules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A thing the platform sells (SRS 2, 21).
 *
 * Read-only to everybody except a Super Admin, and not scoped by shop or
 * tenant: the catalogue is the platform's, and a restaurant sees only the
 * one line of it that it is paying for.
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property array<int, string>|null $modules
 * @property int|null $max_shops
 * @property int|null $max_users
 * @property int|null $max_orders_per_month
 */
class Plan extends Model
{
    use HasUniqueSlug;
    use SoftDeletes;

    public const MONTHLY = 'monthly';

    public const YEARLY = 'yearly';

    /** @var array<int, string> */
    public const PERIODS = [self::MONTHLY, self::YEARLY];

    protected $fillable = [
        'name', 'code', 'slug', 'blurb',
        'monthly_price', 'yearly_price', 'currency', 'trial_days',
        'modules',
        'max_shops', 'max_users', 'max_orders_per_month',
        'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'modules' => 'array',
            'monthly_price' => 'decimal:2',
            'yearly_price' => 'decimal:2',
            'trial_days' => 'integer',
            'max_shops' => 'integer',
            'max_users' => 'integer',
            'max_orders_per_month' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /* --------------------------------------------------------- relations */

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /* ------------------------------------------------------------ scopes */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /* --------------------------------------------------------- behaviour */

    /**
     * The modules this plan grants.
     *
     * Null and empty are different, exactly as they are on a shop. Null is a
     * plan nobody has restricted, and it grants everything; [] is a plan
     * somebody deliberately stripped, and it grants nothing.
     *
     * @return array<int, string>
     */
    public function moduleKeys(): array
    {
        return $this->modules === null
            ? Modules::keys()
            : Modules::sanitise($this->modules);
    }

    /** Does this plan include the given module at all? */
    public function grants(string $module): bool
    {
        return in_array($module, $this->moduleKeys(), true);
    }

    /**
     * The price for one term of the given period.
     *
     * A yearly price that was never set falls back to twelve months, so a
     * monthly-only plan can still be sold by the year without the screen
     * having to special-case it.
     */
    public function priceFor(string $period): float
    {
        if ($period === self::YEARLY) {
            return (float) ($this->yearly_price ?? ((float) $this->monthly_price * 12));
        }

        return (float) $this->monthly_price;
    }

    /**
     * The limits as a readable list, for the plan card and the tenant screen.
     *
     * ---------------------------------------------------------------------
     * There is no branch count here any more
     * ---------------------------------------------------------------------
     *
     * A plan is bought per outlet, so "3 branches" is not something a plan
     * grants - it is a quantity of plans somebody buys. Printing it beside
     * the price told a visitor that ₹1,999 covered three restaurants, which
     * is the exact misreading the pricing page's own "Per outlet" was
     * contradicting.
     *
     * `max_shops` still exists and is still enforced, but only against the
     * blanket rows that predate per-outlet billing - see PlanAccess. It is a
     * fact about an old subscription, not a feature of what is on sale, so
     * it belongs on that subscription's screen and not on the price list.
     *
     * @return array<int, string>
     */
    public function limitLines(): array
    {
        $lines = [];

        $lines[] = 'One outlet';

        $lines[] = $this->max_users === null
            ? 'Unlimited staff accounts'
            : trans_choice(':count staff account|:count staff accounts', $this->max_users, ['count' => $this->max_users]);

        if ($this->max_orders_per_month !== null) {
            $lines[] = number_format($this->max_orders_per_month).' orders a month';
        }

        $granted = count($this->moduleKeys());
        $all = count(Modules::keys());

        $lines[] = $granted >= $all
            ? 'Every module'
            : $granted.' of '.$all.' modules';

        return $lines;
    }

    protected static function slugFallback(): string
    {
        return 'plan';
    }
}
