<?php

namespace App\Models;

use App\Models\Concerns\HasUniqueSlug;
use App\Support\Modules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A thing the platform sells (SRS 2, 21).
 *
 * Read-only to everybody except a Super Admin, and not scoped by shop or
 * tenant: the catalogue is the platform's, and a restaurant sees only the
 * one line of it that it is paying for.
 *
 * Two kinds. A general plan (no `tenant_id`) is on the price list for anybody
 * to buy. A custom plan belongs to one client: it is never on public show,
 * only the platform can put that client on it, and it renews like any other.
 *
 * A plan's modules are a list somebody chose. There is no "everything": a
 * module added to the software later joins no plan until the platform adds
 * it, on the plan form or on the modules matrix.
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
        'tenant_id', 'name', 'code', 'slug', 'blurb',
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

    /** The one client a custom plan was made for. @return BelongsTo<Tenant, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /* ------------------------------------------------------------ scopes */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** What anybody may buy: the public price list. */
    public function scopeOnSale(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereNull('tenant_id');
    }

    /**
     * What one business may be put on: the price list, what was made for it,
     * and the plan it is already on - a plan taken off sale can still be
     * renewed by whoever has it.
     */
    public function scopeAvailableTo(Builder $query, ?int $tenantId, ?int $currentPlanId = null): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $open) => $open
                ->where('is_active', true)
                ->where(fn (Builder $whose) => $whose->whereNull('tenant_id')->orWhere('tenant_id', $tenantId)))
            ->orWhere('id', $currentPlanId ?? 0));
    }

    /* --------------------------------------------------------- behaviour */

    /** Made for one client rather than for sale to anybody. */
    public function isCustom(): bool
    {
        return $this->tenant_id !== null;
    }

    /** @see scopeAvailableTo - the same question about a plan already in hand. */
    public function isAvailableTo(?int $tenantId, ?int $currentPlanId = null): bool
    {
        return $this->id === $currentPlanId
            || ($this->is_active && ($this->tenant_id === null || $this->tenant_id === $tenantId));
    }

    /**
     * The modules this plan grants: exactly what it lists, and nothing it does
     * not. An empty list, or none, grants nothing - the safe way round, because
     * a module must never reach a plan that nobody chose to give it to.
     *
     * @return array<int, string>
     */
    public function moduleKeys(): array
    {
        return Modules::sanitise($this->modules ?? []);
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
     * @return array<int, string>
     */
    public function limitLines(): array
    {
        $lines = [];

        // Outlets, the word on the pricing page: a plan is sold per business, for up to this many.
        $lines[] = match (true) {
            $this->max_shops === null => 'Unlimited outlets',
            $this->max_shops === 1 => '1 outlet',
            default => 'Up to '.$this->max_shops.' outlets',
        };

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
