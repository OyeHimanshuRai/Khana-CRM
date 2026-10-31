<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Shop;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PlatformReport;
use App\Support\PlanAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Project Owner's screens: the whole platform, every company.
 *
 * Kept apart from DashboardController, which is one restaurant's view of
 * itself. Nothing here is scoped to a shop or a tenant, which is why every
 * route needs a `platform.*` ability and PlatformAbilities refuses those to
 * anybody who belongs to a company.
 */
class PlatformController extends Controller
{
    private const PAGE_SIZES = [10, 15, 25, 50];

    public function __construct(private readonly PlatformReport $report) {}

    /* ------------------------------------------------------------ dashboard */

    public function dashboard(): View
    {
        return view('admin.platform.dashboard', [
            'overview' => $this->report->overview(),
            'saas' => $this->report->saas(),
            'monthly' => $this->report->monthly(),
            'yearly' => $this->report->yearly(),
        ]);
    }

    /** The sidebar switch for a restaurant's own menus. */
    public function toggleShopMenus(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->isSuperAdmin(), 403);

        $user->forceFill(['show_shop_menus' => $user->hidesShopMenus()])->save();

        return back();
    }

    /* ---------------------------------------------------------------- shops */

    public function shops(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 15;

        $period = $this->period($request);

        $shops = $this->report->shopList($request->string('q')->toString(), $request->string('status')->toString())
            ->paginate($perPage)
            ->withQueryString();

        $data = [
            'shops' => $shops,
            'figures' => $this->report->figures($shops->pluck('id')->all(), $this->report->periodStart($period)),
            'search' => $request->string('q')->toString(),
            'status' => $request->string('status')->toString(),
            'period' => $period,
            'periods' => PlatformReport::PERIODS,
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
        ];

        return $request->header('X-Fragment')
            ? view('admin.platform._shops', $data)
            : view('admin.platform.shops', $data);
    }

    public function shop(int $shop): View
    {
        $record = $this->report->shopQuery()
            ->with(['tenant' => fn ($q) => $q->withoutGlobalScopes()])
            ->findOrFail($shop);

        $people = User::query()->withoutGlobalScopes()
            ->whereHas('shops', fn ($q) => $q->where('shops.id', $record->id))
            ->with('roles')
            ->orderBy('name')
            ->get();

        return view('admin.platform.shop', [
            'shop' => $record,
            'month' => $this->report->totals($this->report->figures([$record->id], now()->startOfMonth())),
            'allTime' => $this->report->totals($this->report->figures([$record->id])),
            'trend' => $this->report->shopMonthly($record->id),
            'people' => $people,
            'subscription' => PlanAccess::subscriptionForShop($record->id),
            'activity' => $this->activity($record, $people->pluck('id')->all()),
        ]);
    }

    /** What happened at a shop: its staff's actions, and changes to the shop itself. */
    private function activity(Shop $shop, array $userIds)
    {
        return ActivityLog::query()->withoutGlobalScopes()
            ->where(fn ($q) => $q
                ->whereIn('user_id', $userIds ?: [0])
                ->orWhere(fn ($own) => $own->where('subject_type', $shop->getMorphClass())->where('subject_id', $shop->id)))
            ->latest('id')
            ->limit(30)
            ->get();
    }

    public function exportShops(Request $request): StreamedResponse
    {
        $period = $this->period($request);
        $list = $this->report->shopList($request->string('q')->toString(), $request->string('status')->toString());
        $from = $this->report->periodStart($period);

        ActivityLog::record('platform.exported', 'Exported shop performance ('.PlatformReport::PERIODS[$period].')');

        return $this->csv('shop-performance', ['Company', 'Shop', 'Code', 'Status', 'Orders', 'Sales', 'Expenses', 'Profit'], function ($row) use ($list, $from) {
            $list->chunk(200, function ($shops) use ($row, $from) {
                $figures = $this->report->figures($shops->pluck('id')->all(), $from);

                foreach ($shops as $shop) {
                    $line = $figures->get($shop->id, ['orders' => 0, 'sales' => 0, 'expenses' => 0, 'profit' => 0]);

                    $row([
                        $this->text($shop->tenant?->name),
                        $this->text($shop->name),
                        $this->text($shop->code),
                        $this->state($shop),
                        $line['orders'],
                        number_format($line['sales'], 2, '.', ''),
                        number_format($line['expenses'], 2, '.', ''),
                        number_format($line['profit'], 2, '.', ''),
                    ]);
                }
            });
        });
    }

    /* -------------------------------------------------------------- finance */

    public function finance(Request $request): View
    {
        $term = $request->string('q')->toString();

        $payments = $this->payments($term)->paginate(15)->withQueryString();

        $data = [
            'payments' => $payments,
            'search' => $term,
            'saas' => $this->report->saas(),
            'outstanding' => $this->report->outstanding(),
            'monthly' => $this->report->monthly(),
        ];

        return $request->header('X-Fragment')
            ? view('admin.platform._payments', $data)
            : view('admin.platform.finance', $data);
    }

    public function exportFinance(Request $request): StreamedResponse
    {
        $query = $this->payments($request->string('q')->toString());

        ActivityLog::record('platform.exported', 'Exported SaaS payments');

        return $this->csv('saas-payments', ['Reference', 'Paid on', 'Company', 'Method', 'Amount', 'GST', 'Net income'], function ($row) use ($query) {
            $query->chunk(300, function ($payments) use ($row) {
                foreach ($payments as $payment) {
                    $row([
                        $payment->reference,
                        $payment->paid_at?->format('Y-m-d'),
                        $this->text($payment->tenant?->name),
                        $payment->methodLabel(),
                        number_format((float) $payment->amount, 2, '.', ''),
                        number_format((float) $payment->tax_amount, 2, '.', ''),
                        number_format((float) $payment->amount - (float) $payment->tax_amount, 2, '.', ''),
                    ]);
                }
            });
        });
    }

    private function payments(string $term)
    {
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        return SubscriptionPayment::query()
            ->with(['tenant' => fn ($q) => $q->withoutGlobalScopes()])
            ->when($term !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('reference', 'like', $like)
                ->orWhereIn('tenant_id', Tenant::query()->where('name', 'like', $like)->select('id'))))
            ->orderByDesc('paid_at')
            ->orderByDesc('id');
    }

    /* -------------------------------------------------------------- helpers */

    private function period(Request $request): string
    {
        $period = $request->string('period')->toString();

        return array_key_exists($period, PlatformReport::PERIODS) ? $period : 'month';
    }

    private function state(Shop $shop): string
    {
        return match (true) {
            (bool) $shop->tenant?->isSuspended() => 'Suspended',
            ! $shop->is_active => 'Inactive',
            default => 'Active',
        };
    }

    /**
     * A name a stranger typed, made safe for a spreadsheet: a cell starting
     * with one of these is run as a formula when the owner opens the file.
     */
    private function text(?string $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    private function csv(string $name, array $columns, callable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($columns, $rows) {
            $handle = fopen('php://output', 'wb');

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $columns);

            $rows(fn (array $line) => fputcsv($handle, $line));

            fclose($handle);
        }, $name.'-'.now()->format('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
