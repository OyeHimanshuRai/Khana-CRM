<?php

namespace App\Services;

use App\Models\BillingOrder;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Shop;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The whole platform's figures, for the people who own the software.
 *
 * Every query here steps outside the shop and tenant scopes on purpose, so
 * nothing in this class may be reached by a restaurant: see PlatformAbilities,
 * which refuses `platform.*` to anybody who belongs to a company.
 *
 * Two kinds of money live on the Owner Dashboard and must never be added
 * together. A restaurant's sales are the restaurant's; the platform earns only
 * what a restaurant pays for its plan, less the GST collected on it.
 *
 * The sales figures use the definitions the restaurants' own dashboard uses
 * (Invoice::counted, subtotal less cost), and orders the one the plan's
 * allowance uses, so the two views of one shop cannot disagree.
 */
class PlatformReport
{
    /** Days ahead that count as "expiring soon". */
    public const EXPIRING_DAYS = 7;

    public const PERIODS = ['month' => 'This month', 'year' => 'This year', 'all' => 'All time'];

    public function __construct(private readonly SubscriptionService $subscriptions) {}

    /* ------------------------------------------------------------- headline */

    /** @return array<string, mixed> */
    public function overview(): array
    {
        $shops = $this->shopQuery()
            ->leftJoin('tenants', 'tenants.id', '=', 'shops.tenant_id')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN tenants.is_active = 0 THEN 1 ELSE 0 END) as suspended')
            ->selectRaw('SUM(CASE WHEN tenants.is_active <> 0 AND shops.is_active = 0 THEN 1 ELSE 0 END) as inactive')
            ->first();

        $people = User::query()->withoutGlobalScopes()->whereNotNull('tenant_id')->where('is_active', true);
        $owners = (clone $people)->role('Tenant Owner')->count();

        return [
            'companies' => Tenant::query()->count(),
            'companies_suspended' => Tenant::query()->where('is_active', false)->count(),
            'shops' => (int) $shops->total,
            'shops_suspended' => (int) $shops->suspended,
            'shops_inactive' => (int) $shops->inactive,
            'shops_active' => (int) $shops->total - (int) $shops->suspended - (int) $shops->inactive,
            'owners' => $owners,
            'staff' => (clone $people)->count() - $owners,
            'all_time' => $this->totals($this->figures()),
            'month' => $this->totals($this->figures(null, now()->startOfMonth())),
        ];
    }

    /**
     * The platform's own money and who is about to stop paying it.
     *
     * @return array<string, mixed>
     */
    public function saas(): array
    {
        $paid = SubscriptionPayment::query()
            ->selectRaw('COALESCE(SUM(amount - tax_amount), 0) as income')
            ->selectRaw('COALESCE(SUM(tax_amount), 0) as gst')
            ->selectRaw('SUM(CASE WHEN amount > 0 THEN 1 ELSE 0 END) as completed')
            ->first();

        $pending = BillingOrder::query()->open();
        $current = Subscription::query()->current()->get();

        return [
            'income' => (float) $paid->income,
            'gst' => (float) $paid->gst,
            'month_income' => $this->income(now()->startOfMonth(), now()),
            'completed' => (int) $paid->completed,
            'pending' => (clone $pending)->count(),
            'pending_amount' => (float) (clone $pending)->sum('amount'),
            'trialing' => $current->filter(fn (Subscription $s) => $s->state() === Subscription::TRIALING)->count(),
            'due' => $current->filter(fn (Subscription $s) => $s->needsAttention())->count(),
            'expired' => $current->reject(fn (Subscription $s) => $s->isUsable())->count(),
            'expiring' => $this->expiring(),
        ];
    }

    /** @return Collection<int, Subscription> */
    public function expiring(): Collection
    {
        return $this->subscriptions->expiringWithin(self::EXPIRING_DAYS);
    }

    /**
     * Past their term and still owing: in grace, or locked out.
     *
     * @return Collection<int, Subscription>
     */
    public function outstanding(): Collection
    {
        return Subscription::query()
            ->current()
            ->with(['tenant', 'plan'])
            ->orderBy('ends_at')
            ->get()
            ->filter(fn (Subscription $s) => in_array($s->state(), [Subscription::PAST_DUE, Subscription::EXPIRED], true))
            ->values();
    }

    /* --------------------------------------------------------------- charts */

    /**
     * The last twelve months, oldest first.
     *
     * @return array<int, array{label: string, income: float, sales: float}>
     */
    public function monthly(int $months = 12): array
    {
        $start = now()->startOfMonth()->subMonths($months - 1);

        return collect(range(0, $months - 1))->map(function (int $i) use ($start) {
            $from = $start->copy()->addMonths($i);

            return $this->point($from->format('M y'), $from, $from->copy()->endOfMonth());
        })->all();
    }

    /**
     * The last few years, oldest first.
     *
     * @return array<int, array{label: string, income: float, sales: float}>
     */
    public function yearly(int $years = 5): array
    {
        $start = now()->startOfYear()->subYears($years - 1);

        return collect(range(0, $years - 1))->map(function (int $i) use ($start) {
            $from = $start->copy()->addYears($i);

            return $this->point($from->format('Y'), $from, $from->copy()->endOfYear());
        })->all();
    }

    /** @return array{label: string, income: float, sales: float} */
    private function point(string $label, CarbonInterface $from, CarbonInterface $to): array
    {
        return [
            'label' => $label,
            'income' => $this->income($from, $to),
            'sales' => (float) Invoice::query()->withoutGlobalScopes()->counted()
                ->whereBetween('invoiced_at', [$from, $to])
                ->sum('grand_total'),
        ];
    }

    /** What the platform kept: payments, refunds included, less GST. */
    private function income(CarbonInterface $from, CarbonInterface $to): float
    {
        return (float) SubscriptionPayment::query()
            ->whereBetween('paid_at', [$from, $to])
            ->selectRaw('COALESCE(SUM(amount - tax_amount), 0) as income')
            ->value('income');
    }

    /* ---------------------------------------------------------------- shops */

    /** Live shops of every company, removed ones left out. */
    public function shopQuery(): Builder
    {
        return Shop::query()->withoutGlobalScopes()->whereNull('shops.deleted_at');
    }

    /** The list behind Shop Performance, before paging. */
    public function shopList(string $term = '', string $status = ''): Builder
    {
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        return $this->shopQuery()
            ->with(['tenant' => fn ($q) => $q->withoutGlobalScopes()])
            ->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $q) => $q
                ->where('shops.name', 'like', $like)
                ->orWhere('shops.code', 'like', $like)
                ->orWhereIn('shops.tenant_id', Tenant::query()->withoutGlobalScopes()->where('name', 'like', $like)->select('id'))))
            ->when($status !== '', function (Builder $query) use ($status) {
                $suspended = Tenant::query()->withoutGlobalScopes()->where('is_active', false)->select('id');

                match ($status) {
                    'suspended' => $query->whereIn('shops.tenant_id', $suspended),
                    'inactive' => $query->where('shops.is_active', false)->whereNotIn('shops.tenant_id', $suspended),
                    'active' => $query->where('shops.is_active', true)->whereNotIn('shops.tenant_id', $suspended),
                    default => null,
                };
            })
            ->orderBy('shops.name');
    }

    /** Where a period starts; null for all time. */
    public function periodStart(string $period): ?CarbonInterface
    {
        return match ($period) {
            'month' => now()->startOfMonth(),
            'year' => now()->startOfYear(),
            default => null,
        };
    }

    /**
     * Orders, sales, expenses and profit per shop, in one grouped query each.
     *
     * Grouped rather than asked per row, so a page of shops costs four queries
     * however many are on it.
     *
     * @param  array<int, int>|null  $shopIds  null for every shop
     * @return Collection<int, array{orders: int, sales: float, expenses: float, profit: float}>
     */
    public function figures(?array $shopIds = null, ?CarbonInterface $from = null): Collection
    {
        $narrow = fn (Builder $query, string $column) => $query
            ->when($shopIds !== null, fn (Builder $q) => $q->whereIn('shop_id', $shopIds))
            ->when($from !== null, fn (Builder $q) => $q->where($column, '>=', $from));

        $sold = $narrow(Invoice::query()->withoutGlobalScopes()->counted(), 'invoiced_at')
            ->selectRaw('shop_id, SUM(grand_total) as sales, SUM(subtotal) as taxable, SUM(cost_total) as cost')
            ->groupBy('shop_id')
            ->get()
            ->keyBy('shop_id');

        $orders = $narrow(Order::query()->withoutGlobalScopes()->where('status', '!=', Order::CANCELLED), 'created_at')
            ->selectRaw('shop_id, COUNT(*) as aggregate')
            ->groupBy('shop_id')
            ->pluck('aggregate', 'shop_id');

        // A table's bill and an online order's invoice were counted above as orders.
        $counter = $narrow(Invoice::query()->withoutGlobalScopes()->counted(), 'invoiced_at')
            ->whereIn('channel', [Invoice::POS, Invoice::MANUAL])
            ->whereNull('table_session_id')
            ->selectRaw('shop_id, COUNT(*) as aggregate')
            ->groupBy('shop_id')
            ->pluck('aggregate', 'shop_id');

        $spent = $narrow(Expense::query()->withoutGlobalScopes()->where('status', Expense::APPROVED), 'spent_on')
            ->selectRaw('shop_id, SUM(amount) as aggregate')
            ->groupBy('shop_id')
            ->pluck('aggregate', 'shop_id');

        return $sold->keys()->merge($orders->keys())->merge($counter->keys())->merge($spent->keys())->unique()
            ->mapWithKeys(function ($shopId) use ($sold, $orders, $counter, $spent) {
                $row = $sold->get($shopId);
                $expenses = (float) ($spent[$shopId] ?? 0);

                return [(int) $shopId => [
                    'orders' => (int) ($orders[$shopId] ?? 0) + (int) ($counter[$shopId] ?? 0),
                    'sales' => (float) ($row->sales ?? 0),
                    'expenses' => $expenses,
                    'profit' => (float) ($row->taxable ?? 0) - (float) ($row->cost ?? 0) - $expenses,
                ]];
            });
    }

    /**
     * @param  Collection<int, array{orders: int, sales: float, expenses: float, profit: float}>  $figures
     * @return array{orders: int, sales: float, expenses: float, profit: float}
     */
    public function totals(Collection $figures): array
    {
        return [
            'orders' => (int) $figures->sum('orders'),
            'sales' => (float) $figures->sum('sales'),
            'expenses' => (float) $figures->sum('expenses'),
            'profit' => (float) $figures->sum('profit'),
        ];
    }

    /** Twelve months of one shop's sales, for its own page. */
    public function shopMonthly(int $shopId, int $months = 12): array
    {
        $start = Carbon::now()->startOfMonth()->subMonths($months - 1);

        return collect(range(0, $months - 1))->map(function (int $i) use ($start, $shopId) {
            $from = $start->copy()->addMonths($i);

            return [
                'label' => $from->format('M y'),
                'sales' => (float) Invoice::query()->withoutGlobalScopes()->counted()
                    ->where('shop_id', $shopId)
                    ->whereBetween('invoiced_at', [$from, $from->copy()->endOfMonth()])
                    ->sum('grand_total'),
            ];
        })->all();
    }
}
