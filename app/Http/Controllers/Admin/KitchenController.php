<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\KitchenStation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Services\KitchenService;
use App\Services\PrintService;
use App\Support\ApiResponse;
use App\Support\CurrentShop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * The kitchen display (§9).
 *
 * ---------------------------------------------------------------------------
 * Why this polls instead of holding a socket open
 * ---------------------------------------------------------------------------
 *
 * §9 asks for "WebSockets or another real-time transport", and this is the
 * other one: the board re-fetches itself every few seconds and swaps the
 * fragment in. A kitchen screen has one job, sits on the restaurant's own
 * wifi, and the worst case is a ticket arriving six seconds late.
 *
 * The trade is deliberate. A socket needs a broker running beside PHP, and a
 * broker that dies at eight on a Saturday takes the kitchen's screen with it
 * in a way nobody notices until the complaints start - whereas a poll that
 * fails just retries. When this platform grows a broadcast layer the same
 * fragment can be pushed instead of pulled; nothing above this line changes.
 *
 * ---------------------------------------------------------------------------
 * The station is sticky
 * ---------------------------------------------------------------------------
 *
 * A screen bolted to the wall by the tandoor is the tandoor's screen, and it
 * has to still be the tandoor's screen after somebody reloads it. The choice
 * is kept in the session rather than only in the URL, so the bare /kitchen
 * URL - which is what a kiosk browser opens on boot - comes back where it was.
 */
class KitchenController extends Controller
{
    /** Where the sticky station choice is kept. See the class note. */
    private const STATION_KEY = 'kds_station_id';

    public function __construct(private readonly KitchenService $kitchen) {}

    public function index(Request $request): View|RedirectResponse
    {
        /*
         | One kitchen at a time. A board mixing two branches' tickets is not
         | a kitchen screen - a cook in one building cannot cook the food in
         | another - and the stations it would list belong to both.
         */
        if (CurrentShop::id() === null) {
            return redirect()
                ->route('admin.dashboard')
                ->with('error', 'Choose a single shop — a kitchen display shows one kitchen.');
        }

        $stations = KitchenStation::query()
            ->active()
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $station = $this->station($request, $stations);
        $type = $this->type($request);

        $tickets = $this->kitchen->feed($station, $type);

        $data = [
            'stations' => $stations,
            'station' => $station,
            'tickets' => $tickets,
            'type' => $type,
            'types' => Order::TYPES,
            'counts' => $this->kitchen->counts($station),
            'flow' => Order::KITCHEN_FLOW,
            'labels' => Order::STATUSES,
            /*
             | What the poller compares to decide whether anything new has
             | landed. The highest id on the board, not the number of tickets:
             | a ticket bumped away and a new one arriving in the same seven
             | seconds leaves the count unchanged and must still ring.
             */
            'latest' => (int) ($tickets->max('id') ?? 0),
        ];

        return $request->header('X-Fragment')
            ? view('admin.kitchen._board', $data)
            : view('admin.kitchen.index', $data);
    }

    /**
     * Which station this screen is showing.
     *
     * `all` is a real answer and not an absence: the pass wants every station
     * on one screen, and it has to survive a reload the same way a single
     * station does. Hence the sentinel in the session rather than clearing it.
     */
    private function station(Request $request, $stations): ?KitchenStation
    {
        if ($request->has('station')) {
            $asked = $request->string('station')->toString();

            if ($asked === 'all' || $asked === '') {
                $request->session()->put(self::STATION_KEY, 'all');

                return null;
            }

            $found = $stations->firstWhere('id', (int) $asked);

            if ($found) {
                $request->session()->put(self::STATION_KEY, $found->id);

                return $found;
            }
        }

        $remembered = $request->session()->get(self::STATION_KEY);

        if ($remembered === 'all') {
            return null;
        }

        return $remembered
            ? $stations->firstWhere('id', (int) $remembered)
            : null;
    }

    private function type(Request $request): ?string
    {
        $type = $request->string('type')->toString();

        return array_key_exists($type, Order::TYPES) ? $type : null;
    }

    /* ------------------------------------------------------------- bumps */

    /**
     * Move a whole ticket along - the button a cook actually presses.
     *
     * Scoped to the station the screen is on, so the tandoor bumping its own
     * work never touches what the bar is still pouring.
     */
    public function bumpTicket(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'to' => ['nullable', 'string', Rule::in(Order::KITCHEN_FLOW)],
            'station_id' => ['nullable', 'integer'],
        ]);

        $station = $this->stationById($data['station_id'] ?? null);

        try {
            $order = $this->kitchen->bumpTicket($order, $station, $data['to'] ?? null);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        return ApiResponse::success(
            $this->bumpMessage($order, $station),
            ['id' => $order->id, 'status' => $order->status, 'label' => $order->statusLabel()],
        );
    }

    /**
     * What to say after a station bumped its own work.
     *
     * The order's status is the wrong thing to report here: a bar that just
     * marked its drinks ready would be told "GF-01/2 - Placed", because the
     * ticket is still waiting on the tandoor. So the station's own rung is
     * named, and the ticket's only when it lags behind - which is the moment
     * a cook needs to know the table is not going out yet.
     */
    private function bumpMessage(Order $order, ?KitchenStation $station): string
    {
        if ($station === null) {
            return sprintf('%s → %s', $order->order_number, $order->statusLabel());
        }

        $here = $order->items()
            ->whereNotNull('kitchen_status')
            ->atStation($station->id)
            ->get()
            ->map(fn (OrderItem $line) => array_search($line->kitchen_status, Order::KITCHEN_FLOW, true))
            ->filter(fn ($at) => $at !== false)
            ->min();

        if ($here === null) {
            return sprintf('%s → %s', $order->order_number, $order->statusLabel());
        }

        $rung = Order::KITCHEN_FLOW[$here];
        $label = Order::STATUSES[$rung] ?? $rung;

        if ($rung === $order->status) {
            return sprintf('%s · %s → %s', $order->order_number, $station->name, $label);
        }

        return sprintf(
            '%s · %s → %s. The ticket is still %s.',
            $order->order_number,
            $station->name,
            $label,
            $order->statusLabel(),
        );
    }

    /** Move one dish along, for a ticket whose lines are not in step. */
    public function bumpLine(Request $request, OrderItem $item): JsonResponse
    {
        $data = $request->validate([
            'to' => ['nullable', 'string', Rule::in(Order::KITCHEN_FLOW)],
        ]);

        /*
         | {item} is bound by id and an order line carries no shop_id of its
         | own, so nothing here stopped one kitchen advancing another
         | restaurant's live ticket - and rewriting the timestamps its
         | prep-time report is built from. Order does carry the branch, so
         | asking for it through the scope is the whole check.
         */
        abort_unless(Order::query()->whereKey($item->order_id)->exists(), 404);

        try {
            $item = $this->kitchen->bumpLine($item, $data['to'] ?? null);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        return ApiResponse::success(
            sprintf('%s → %s', $item->title(), $item->kitchenStatusLabel()),
            ['id' => $item->id, 'status' => $item->kitchen_status],
        );
    }

    /**
     * Send a ticket back down the ladder (§9 - reopen).
     *
     * A reason is asked for and not optional: this rewrites the timestamps
     * the preparation-time report is built from, and a recall nobody can
     * explain a week later is how that report stops being trusted.
     */
    public function recall(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required', 'string', Rule::in(Order::KITCHEN_FLOW)],
            'station_id' => ['nullable', 'integer'],
            'reason' => ['required', 'string', 'max:190'],
        ], [
            'reason.required' => 'Say why the ticket is going back.',
        ]);

        try {
            $order = $this->kitchen->recall(
                $order,
                $data['to'],
                $this->stationById($data['station_id'] ?? null),
                $data['reason'],
            );
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        return ApiResponse::success(
            sprintf('%s recalled to %s.', $order->order_number, $order->statusLabel()),
            ['id' => $order->id, 'status' => $order->status],
        );
    }

    /** The recall dialog - the stages this ticket can actually go back to. */
    public function recallForm(Request $request, Order $order): View
    {
        $at = array_search($order->status, Order::KITCHEN_FLOW, true);

        return view('admin.kitchen._recall', [
            'order' => $order,
            'station' => $this->stationById($request->integer('station_id') ?: null),
            // Only what is behind it. Offering the rung it is already on, or
            // one ahead, would make this a second and unlogged way to bump.
            'stages' => $at === false
                ? []
                : array_slice(Order::KITCHEN_FLOW, 0, max(0, (int) $at)),
            'labels' => Order::STATUSES,
        ]);
    }

    /**
     * The KOT slip (§9 - reprint).
     *
     * Deliberately not a copy of the bill: no prices, no tax, no totals. A
     * kitchen slip that carried them would be handed to a guest by accident
     * about once a month, and a KOT is a work order, not a receipt.
     */
    public function kot(Request $request, Order $order): View
    {
        $station = $this->stationById($request->integer('station_id') ?: null);

        /*
         | Record the attempt (§6 - "KOT print and reprint with audit trail").
         |
         | Opening this page is as close as this system gets to knowing a
         | browser print happened; whether paper actually came out is between
         | the operator and their print dialog, and the job stays `queued` to
         | say exactly that.
         |
         | Whether it is a reprint is decided from earlier attempts that were
         | not failures. A printer that jammed on the first try printed
         | nothing for this to be a copy of, so a failed job does not make the
         | next one a reprint - which is the distinction that makes the
         | reprint count worth reading at all.
         */
        $printed = PrintJob::query()
            ->forSame($order, Printer::KOT)
            ->where('status', '!=', PrintJob::FAILED)
            ->when($station, fn ($q) => $q->where('kitchen_station_id', $station->id))
            ->exists();

        PrintJob::create([
            'shop_id' => $order->shop_id,
            'printable_type' => $order->getMorphClass(),
            'printable_id' => $order->getKey(),
            'kind' => Printer::KOT,
            'kitchen_station_id' => $station?->id,
            'status' => PrintJob::QUEUED,
            'is_reprint' => $printed,
            'reason' => $printed ? $request->string('reason')->toString() ?: null : null,
            'user_id' => $request->user()?->id,
        ]);

        return view('admin.kitchen.kot', [
            'order' => $order->load([
                'items' => fn ($q) => $q->with(['modifiers', 'kitchenStation:id,name,code,prep_minutes'])->orderBy('id'),
                'tableSession.table.floor',
            ]),
            'station' => $station,
            'lines' => $order->items
                ->when($station, fn ($lines) => $lines->where('kitchen_station_id', $station->id))
                ->values(),
        ]);
    }

    /**
     * Push a kitchen ticket straight at the printer beside the station.
     *
     * The whole point of the printer registry: no print dialog, nobody at a
     * screen, paper beside the tandoor. Falls back to saying so plainly when
     * no network printer is configured, rather than pretending.
     */
    public function sendKot(Request $request, Order $order, PrintService $printing): JsonResponse
    {
        $station = $this->stationById($request->integer('station_id') ?: null);

        $reprint = $request->boolean('reprint');
        $reason = $request->string('reason')->toString() ?: null;

        $jobs = $printing->kot($order, $station, $reprint, $reason);

        $sent = $jobs->where('status', PrintJob::SENT);
        $failed = $jobs->where('status', PrintJob::FAILED);

        if ($sent->isEmpty() && $failed->isEmpty()) {
            return ApiResponse::error(
                'No network printer is set up for this ticket, so there is nothing to send to. '
                .'Use the print page, or add one under Settings > Printers.'
            );
        }

        if ($failed->isNotEmpty()) {
            /*
             | Named rather than counted. "1 of 2 failed" sends somebody to
             | the print log; naming the tandoor sends them to the tandoor.
             */
            return ApiResponse::error(sprintf(
                'Could not print at %s: %s',
                $failed->map(fn (PrintJob $job) => $job->printer?->name ?? 'a printer')->implode(', '),
                $failed->first()->error ?? 'no reason given',
            ));
        }

        return ApiResponse::success(sprintf(
            'Sent to %s.',
            $sent->map(fn (PrintJob $job) => $job->printer?->name ?? 'the printer')->unique()->implode(', '),
        ));
    }

    /**
     * A station by id, narrowed to this branch by the model's global scope.
     *
     * Null for "every station", which is also what an id from another shop
     * resolves to - the scope simply does not find it, and the request ends
     * up showing the whole board rather than somebody else's.
     */
    private function stationById(?int $id): ?KitchenStation
    {
        return $id ? KitchenStation::query()->find($id) : null;
    }
}
