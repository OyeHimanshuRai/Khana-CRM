<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentReminder;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\RestaurantTable;
use App\Models\StockTransfer;
use App\Support\CurrentShop;
use App\Support\Modules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * The dashboard.
 *
 * Every figure is read for the shop in context, and every one of them is
 * gated: a cashier who cannot see the profit report does not get a profit
 * tile, rather than getting one that says nothing. The widgets a user is
 * not entitled to simply are not there.
 *
 * Deliberately not cached. These numbers are the reason people open the
 * screen, and a stale sales figure is worse than a slow one - the queries
 * are all indexed and shop-scoped, so the cost is small.
 */
class DashboardController extends Controller
{
    public function __invoke(): View
    {
        return view('admin.dashboard', [
            'shop' => CurrentShop::get(),
            'sales' => $this->when('reports.sales_report.view', fn () => $this->sales()),
            'profit' => $this->when('reports.profit_report.view', fn () => $this->profit()),
            'stock' => $this->when('inventory.stock.view', fn () => $this->stock()),
            'room' => $this->when('dining.tables.view', fn () => $this->room()),
            'kitchen' => $this->when('kitchen.tickets.view', fn () => $this->kitchen()),
            'dues' => $this->when('crm.dues.view', fn () => $this->dues()),
            'payments' => $this->when('finance.payments.view', fn () => $this->payments()),
            'customers' => $this->when('crm.customers.view', fn () => $this->customers()),
            'topProducts' => $this->when('reports.sales_report.view', fn () => $this->topProducts()),
            'recentInvoices' => $this->when('sales.invoices.view', fn () => $this->recentInvoices()),

            'alerts' => $this->alerts(),
        ]);
    }

    /**
     * Compute a widget only if this branch runs it and the reader may see it.
     *
     * Two gates, same pair as everywhere else: the shop decides whether the
     * line of business exists here at all, the permission decides whether
     * this person sees it. A shop that does no purchasing gets no supplier
     * tile even for its owner; a cashier gets no profit tile in a shop that
     * has both.
     *
     * Returning null rather than an empty array so the view can tell "not
     * there" from "nothing to show" - the first hides the tile, the second
     * shows an honest zero.
     */
    private function when(string $permission, callable $compute): mixed
    {
        return $this->may($permission) ? $compute() : null;
    }

    /** The same pair of gates, for the alert list, which returns rows not tiles. */
    private function may(string $permission): bool
    {
        return Modules::allows($permission) && Gate::allows($permission);
    }

    /* -------------------------------------------------------------- sales */

    /**
     * @return array<string, mixed>
     */
    private function sales(): array
    {
        $counted = fn () => Invoice::query()->counted();

        $today = (float) $counted()->whereDate('invoiced_at', today())->sum('grand_total');
        $yesterday = (float) $counted()->whereDate('invoiced_at', today()->subDay())->sum('grand_total');

        return [
            'today' => $today,
            'today_count' => $counted()->whereDate('invoiced_at', today())->count(),
            // Yesterday at this hour would be a fairer comparison, but a
            // shop reads "vs yesterday" as the whole day - so that is what
            // it says.
            'yesterday' => $yesterday,
            'change' => $yesterday > 0 ? (($today - $yesterday) / $yesterday) * 100 : null,
            'month' => (float) $counted()
                ->whereBetween('invoiced_at', [today()->startOfMonth(), now()])
                ->sum('grand_total'),
            'year' => (float) $counted()
                ->whereBetween('invoiced_at', [today()->startOfYear(), now()])
                ->sum('grand_total'),
            'week' => $this->weekTrend(),
        ];
    }

    /**
     * Sales for each of the last seven days, oldest first.
     *
     * A sparkline's worth of context: the number on its own does not say
     * whether today is good.
     *
     * @return Collection<int, array{label: string, total: float}>
     */
    private function weekTrend(): Collection
    {
        $rows = Invoice::query()
            ->counted()
            ->where('invoiced_at', '>=', today()->subDays(6))
            ->selectRaw('DATE(invoiced_at) as day, SUM(grand_total) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        return collect(range(6, 0))->map(function (int $back) use ($rows) {
            $day = today()->subDays($back);

            return [
                'label' => $day->format('D'),
                'total' => (float) ($rows[$day->toDateString()] ?? 0),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function profit(): array
    {
        $month = Invoice::query()
            ->counted()
            ->whereBetween('invoiced_at', [today()->startOfMonth(), now()])
            ->selectRaw('COALESCE(SUM(subtotal), 0) as revenue')
            ->selectRaw('COALESCE(SUM(cost_total), 0) as cost')
            ->first();

        $revenue = (float) ($month->revenue ?? 0);
        $cost = (float) ($month->cost ?? 0);

        return [
            'revenue' => $revenue,
            'cost' => $cost,
            'gross' => $revenue - $cost,
            // Margin on nothing is not zero, it is undefined - and printing
            // "0%" on a day with no sales is the kind of thing that gets a
            // dashboard mistrusted.
            'margin' => $revenue > 0 ? (($revenue - $cost) / $revenue) * 100 : null,
        ];
    }

    /* --------------------------------------------------------- the room */

    /**
     * Table occupancy (§5).
     *
     * One grouped query rather than five counts, and only tables in service:
     * a table switched off seats nobody, and counting it would make
     * "available" read higher than the room actually is.
     *
     * @return array<string, int>
     */
    private function room(): array
    {
        $byStatus = RestaurantTable::query()
            ->active()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $room = ['total' => (int) $byStatus->sum(), 'seats' => 0];

        foreach (array_keys(RestaurantTable::STATUSES) as $status) {
            $room[$status] = (int) ($byStatus[$status] ?? 0);
        }

        $room['seats'] = (int) RestaurantTable::query()->active()->sum('capacity');

        /*
         | Occupancy counts billing as seated. The guests have not left, and a
         | percentage that said the table was free while somebody was settling
         | the bill would send the next party to it.
         */
        $seated = $room[RestaurantTable::OCCUPIED] + $room[RestaurantTable::BILLING];

        $room['occupancy'] = $room['total'] > 0
            ? (int) round($seated / $room['total'] * 100)
            : 0;

        return $room;
    }

    /* ------------------------------------------------------- the kitchen */

    /**
     * Live order count (§5): New, Preparing, Ready, Served and Cancelled.
     *
     * Counted in *tickets*, not lines - the opposite of the KDS's own header
     * row, and deliberately so. A manager glancing at this asks "how many
     * tables are waiting", and a cook at the pass asks "how many dishes do I
     * have to make". Both are right, and they are different numbers.
     *
     * Today only. A cancelled order from March is not a live order, and a
     * "served" count that grew all year would say nothing about tonight.
     *
     * @return array<string, int>
     */
    private function kitchen(): array
    {
        $byStatus = Order::query()
            ->whereDate('created_at', today())
            ->whereHas('items', fn (Builder $q) => $q->whereNotNull('kitchen_status'))
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $counts = [];

        foreach (array_merge(Order::KITCHEN_FLOW, [Order::CANCELLED]) as $status) {
            $counts[$status] = (int) ($byStatus[$status] ?? 0);
        }

        // What the kitchen still has to make: everything short of the pass.
        $counts['working'] = $counts[Order::PENDING]
            + $counts[Order::CONFIRMED]
            + $counts[Order::PREPARING];

        return $counts;
    }

    /* -------------------------------------------------------------- stock */

    /**
     * @return array<string, mixed>
     */
    private function stock(): array
    {
        return [
            'value' => (float) ProductStock::query()
                ->selectRaw('COALESCE(SUM(quantity * average_cost), 0) as total')
                ->value('total'),
            'lines' => ProductStock::query()->where('quantity', '>', 0)->count(),
            'low' => $this->lowStockCount(),
            'out' => Product::query()
                ->active()
                ->whereDoesntHave('stocks', fn ($q) => $q->where('quantity', '>', 0))
                ->count(),
            'near_expiry' => Batch::query()->nearExpiry()
                ->whereHas('stocks', fn ($q) => $q->where('quantity', '>', 0))
                ->count(),
            'expired' => Batch::query()->expired()
                ->whereHas('stocks', fn ($q) => $q->where('quantity', '>', 0))
                ->count(),
        ];
    }

    /**
     * Products at or below their reorder level.
     *
     * The sub-query is built from shop ids that came out of the pivot, never
     * from request input, so interpolating them is safe.
     */
    private function lowStockCount(): int
    {
        $ids = CurrentShop::id() !== null
            ? [CurrentShop::id()]
            : (CurrentShop::accessibleIds() ?: [0]);

        $list = implode(',', array_map('intval', $ids));

        return Product::query()
            ->active()
            // A dish cannot run low: there is no shelf of it. Its ingredients
            // can, and they are rows in their own right - see tracksStock().
            ->where('is_made_to_order', false)
            ->where('reorder_level', '>', 0)
            ->whereRaw(
                '(SELECT COALESCE(SUM(quantity), 0) FROM product_stocks
                    WHERE product_stocks.product_id = products.id
                    AND product_stocks.shop_id IN ('.$list.')
                 ) <= products.reorder_level'
            )
            ->count();
    }

    /* --------------------------------------------------------------- dues */

    /**
     * @return array<string, mixed>
     */
    private function dues(): array
    {
        return [
            /*
             | Money owed on bills, not balances carried on accounts.
             |
             | These are two different figures and this tile used to show the
             | other one: the sum of customer ledger balances, which includes
             | opening balances and adjustments that never were an invoice,
             | and excludes every walk-in bill left unpaid because a walk-in
             | has no account to carry it.
             |
             | The result was a Dashboard and an Invoices screen showing
             | different "Outstanding" totals for the same afternoon, and the
             | overdue figures sitting beside this one in the dues panel were
             | already invoice-based - so the panel did not add up either.
             | One question, one source: what do the bills say is unpaid.
             |
             | The customer-balance view of the same money is the Dues screen,
             | which is what it is for.
             */
            'outstanding' => (float) Invoice::query()->outstanding()->sum('due_total'),
            'customers' => Customer::query()->withDues()->count(),
            'due_today' => (float) Invoice::query()
                ->outstanding()
                ->whereDate('due_date', today())
                ->sum('due_total'),
            'upcoming' => (float) Invoice::query()->dueWithin(7)->sum('due_total'),
            'overdue' => (float) Invoice::query()->overdue()->sum('due_total'),
            'overdue_count' => Invoice::query()->overdue()->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payments(): array
    {
        $today = Payment::query()
            ->effective()
            ->incoming()
            ->whereDate('paid_at', today());

        return [
            'today' => (float) (clone $today)->sum('amount'),
            'by_method' => (clone $today)
                ->selectRaw('method, SUM(amount) as total')
                ->groupBy('method')
                ->pluck('total', 'method'),
            'pending' => (float) Payment::query()
                ->where('status', Payment::PENDING)
                ->sum('amount'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function customers(): array
    {
        return [
            'total' => Customer::query()->active()->count(),
            'new_this_month' => Customer::query()
                ->where('created_at', '>=', today()->startOfMonth())
                ->count(),
            // "Active" means bought something in the last 90 days, which is
            // what a shop means by it - not that the row is not disabled.
            'buying' => Customer::query()
                ->whereExists(function ($sub) {
                    $sub->selectRaw('1')
                        ->from('invoices')
                        ->whereColumn('invoices.customer_id', 'customers.id')
                        ->whereNotIn('invoices.status', [Invoice::DRAFT, Invoice::CANCELLED])
                        ->where('invoices.invoiced_at', '>=', today()->subDays(90));
                })
                ->count(),
        ];
    }

    /**
     * The month's best sellers by value.
     *
     * @return Collection<int, object>
     */
    private function topProducts(): Collection
    {
        $ids = CurrentShop::id() !== null
            ? [CurrentShop::id()]
            : (CurrentShop::accessibleIds() ?: [0]);

        return InvoiceItem::query()
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->whereIn('invoices.shop_id', $ids)
            ->whereNotIn('invoices.status', [Invoice::DRAFT, Invoice::CANCELLED])
            ->where('invoices.invoiced_at', '>=', today()->startOfMonth())
            ->selectRaw('invoice_items.product_name, invoice_items.sku')
            ->selectRaw('SUM(invoice_items.quantity) as quantity')
            ->selectRaw('SUM(invoice_items.line_total) as revenue')
            ->groupBy('invoice_items.product_name', 'invoice_items.sku')
            ->orderByDesc('revenue')
            ->limit(8)
            ->get();
    }

    /**
     * @return Collection<int, Invoice>
     */
    private function recentInvoices(): Collection
    {
        return Invoice::query()
            ->with('customer:id,name')
            ->orderByDesc('invoiced_at')
            ->orderByDesc('id')
            ->limit(8)
            ->get();
    }

    /* ------------------------------------------------------------ alerts */

    /**
     * The things somebody has to do something about.
     *
     * Only what the reader may act on, and only what is actually true -
     * an alert list that is always full stops being read.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function alerts(): Collection
    {
        $alerts = collect();

        if ($this->may('inventory.stock.view')) {
            $low = $this->lowStockCount();

            if ($low > 0) {
                $alerts->push([
                    'tone' => 'warning',
                    'icon' => 'trend-down',
                    'title' => $low.' product'.($low === 1 ? '' : 's').' at or below reorder level',
                    'action' => route('admin.products.index', ['stock' => 'low']),
                    'label' => 'See the list',
                ]);
            }

            $expired = Batch::query()->expired()
                ->whereHas('stocks', fn ($q) => $q->where('quantity', '>', 0))
                ->count();

            if ($expired > 0) {
                $alerts->push([
                    'tone' => 'danger',
                    'icon' => 'x',
                    'title' => $expired.' expired batch'.($expired === 1 ? '' : 'es').' still holding stock',
                    'action' => route('admin.batches.index', ['expiry' => 'expired', 'stocked' => 'held']),
                    'label' => 'Write them off',
                ]);
            }

            $near = Batch::query()->nearExpiry()
                ->whereHas('stocks', fn ($q) => $q->where('quantity', '>', 0))
                ->count();

            if ($near > 0) {
                $alerts->push([
                    'tone' => 'warning',
                    'icon' => 'clock',
                    'title' => $near.' batch'.($near === 1 ? '' : 'es').' expiring within '.Batch::NEAR_EXPIRY_DAYS.' days',
                    'action' => route('admin.batches.index', ['expiry' => 'near', 'stocked' => 'held']),
                    'label' => 'Move them on',
                ]);
            }
        }

        if ($this->may('crm.dues.view')) {
            $overdue = Invoice::query()->overdue()->count();

            if ($overdue > 0) {
                $alerts->push([
                    'tone' => 'danger',
                    'icon' => 'wallet',
                    'title' => $overdue.' invoice'.($overdue === 1 ? '' : 's').' past their due date',
                    'action' => route('admin.invoices.index', ['settlement' => 'overdue']),
                    'label' => 'Chase them',
                ]);
            }
        }

        if ($this->may('inventory.transfers.view')) {
            $incoming = StockTransfer::allShops()
                ->incomingFor(CurrentShop::accessibleIds())
                ->where('status', StockTransfer::DISPATCHED)
                ->count();

            if ($incoming > 0) {
                $alerts->push([
                    'tone' => 'info',
                    'icon' => 'truck',
                    'title' => $incoming.' consignment'.($incoming === 1 ? '' : 's').' in transit to you',
                    'action' => route('admin.stock-transfers.index', ['direction' => 'incoming']),
                    'label' => 'Book them in',
                ]);
            }
        }

        if ($this->may('crm.reminders.view')) {
            $failed = PaymentReminder::query()->where('status', PaymentReminder::FAILED)->count();

            if ($failed > 0) {
                $alerts->push([
                    'tone' => 'warning',
                    'icon' => 'mail',
                    'title' => $failed.' payment reminder'.($failed === 1 ? '' : 's').' could not be sent',
                    'action' => route('admin.reminders.index', ['status' => PaymentReminder::FAILED]),
                    'label' => 'Look into it',
                ]);
            }
        }

        if ($this->may('finance.payments.view')) {
            $pending = Payment::query()->where('status', Payment::PENDING)->count();

            if ($pending > 0) {
                $alerts->push([
                    'tone' => 'info',
                    'icon' => 'clock',
                    'title' => $pending.' payment'.($pending === 1 ? '' : 's').' waiting to clear',
                    'action' => route('admin.payments.index', ['status' => Payment::PENDING]),
                    'label' => 'Review',
                ]);
            }
        }

        return $alerts;
    }
}
