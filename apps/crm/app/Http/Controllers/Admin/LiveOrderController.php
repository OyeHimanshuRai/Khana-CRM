<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\CurrentShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Live orders (§4, §5).
 *
 * ---------------------------------------------------------------------------
 * Why this is not the kitchen display
 * ---------------------------------------------------------------------------
 *
 * The KDS is the cook's screen: one station, dark, big type, and a bump button
 * as the only thing you can press. This is the manager's - every channel at
 * once, in one list, oldest first, with the question "what is running late"
 * answered in the first column somebody looks at.
 *
 * They also hold different things. A takeaway order that nobody routed to a
 * station has no kitchen lines and never appears on the board; it is still very
 * much a live order and somebody is standing at the counter waiting for it.
 *
 * ---------------------------------------------------------------------------
 * A list, not columns
 * ---------------------------------------------------------------------------
 *
 * Columns are for working a queue - the kitchen's job. A manager is scanning
 * for the exception, and a list sorted by how long something has been waiting
 * puts the exception at the top without anybody having to look for it.
 */
class LiveOrderController extends Controller
{
    /**
     * Statuses that mean somebody is still waiting.
     *
     * `packing` and `shipped` are the web ladder's middle; `confirmed`,
     * `preparing` and `ready` are shared. Read from Order rather than spelled
     * out, so a new rung on either ladder appears here without an edit.
     *
     * @var array<int, string>
     */
    private const IN_FLIGHT = [
        Order::PENDING,
        Order::CONFIRMED,
        Order::PREPARING,
        Order::PACKING,
        Order::READY,
        Order::SHIPPED,
    ];

    /**
     * Minutes after which an order is worth shouting about.
     *
     * A flat number, unlike the kitchen's per-station windows, because this
     * screen spans four channels and a delivery order's clock and a table's
     * are not comparable anyway. It is a prompt to look, not a measurement -
     * the measurement is the preparation-time report.
     */
    private const LATE_AFTER = 20;

    public function index(Request $request): View
    {
        $type = $this->type($request);
        $status = $this->status($request);

        $orders = $this->filtered($request, $type, $status)
            ->with([
                'customer:id,name,mobile',
                'tableSession.table:id,name,code,floor_id',
                'tableSession.table.floor:id,name',
            ])
            ->withCount('items')
            ->orderByRaw('COALESCE(placed_at, created_at) asc')
            ->limit(200)
            ->get();

        $data = [
            'orders' => $orders,
            'type' => $type,
            'types' => Order::TYPES,
            'status' => $status,
            'statuses' => $this->stageLabels(),
            'counts' => $this->counts(),
            'late' => self::LATE_AFTER,
            'showsShop' => CurrentShop::id() === null,
            /*
             | What the poller compares. The highest id in flight, not the
             | count: an order finishing and a new one arriving in the same
             | ten seconds leaves the count unchanged.
             */
            'latest' => (int) ($orders->max('id') ?? 0),
        ];

        return $request->header('X-Fragment')
            ? view('admin.live-orders._list', $data)
            : view('admin.live-orders.index', $data);
    }

    /**
     * @return Builder<Order>
     */
    private function filtered(Request $request, ?string $type, ?string $status): Builder
    {
        return Order::query()
            ->whereIn('status', self::IN_FLIGHT)
            ->ofType($type)
            ->when($status, fn (Builder $q, string $s) => $q->where('status', $s))
            ->search($request->string('q')->toString());
    }

    /**
     * The live order count §5 asks the dashboard for, in one query.
     *
     * Cancelled is counted separately and only for today: an order cancelled
     * in March is not live, but "three cancelled since this morning" is
     * exactly the kind of thing a manager wants to notice.
     *
     * @return array<string, int>
     */
    private function counts(): array
    {
        $byStatus = Order::query()
            ->whereIn('status', self::IN_FLIGHT)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $counts = [];

        foreach (self::IN_FLIGHT as $status) {
            $counts[$status] = (int) ($byStatus[$status] ?? 0);
        }

        $counts['total'] = (int) $byStatus->sum();

        $counts['late'] = Order::query()
            ->whereIn('status', self::IN_FLIGHT)
            ->whereRaw('COALESCE(placed_at, created_at) < ?', [now()->subMinutes(self::LATE_AFTER)])
            ->count();

        $counts['cancelled'] = Order::query()
            ->where('status', Order::CANCELLED)
            ->whereDate('cancelled_at', today())
            ->count();

        return $counts;
    }

    /**
     * The stages that can actually appear, with their labels.
     *
     * Built from what is in flight rather than from the whole vocabulary, so
     * the filter never offers "Delivered" on a screen that by definition
     * cannot show it.
     *
     * @return Collection<string, string>
     */
    private function stageLabels(): Collection
    {
        return collect(self::IN_FLIGHT)
            ->mapWithKeys(fn (string $status) => [$status => Order::STATUSES[$status] ?? $status]);
    }

    private function type(Request $request): ?string
    {
        $type = $request->string('type')->toString();

        return array_key_exists($type, Order::TYPES) ? $type : null;
    }

    private function status(Request $request): ?string
    {
        $status = $request->string('status')->toString();

        return in_array($status, self::IN_FLIGHT, true) ? $status : null;
    }
}
