<?php

namespace App\Services;

use App\Events\ShopBoardChanged;
use App\Models\ActivityLog;
use App\Models\KitchenStation;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The kitchen display: what is outstanding, and moving it along (§9).
 *
 * ---------------------------------------------------------------------------
 * The ticket's status is derived, not set
 * ---------------------------------------------------------------------------
 *
 * A cook bumps a *line* - their line, at their station. The order's status is
 * then the least-advanced of its kitchen lines: drinks poured and a kebab
 * still on means the ticket is Preparing, and it turns Ready only when the
 * last station lets go of it.
 *
 * That is the whole reason a line carries a status at all. The alternative -
 * one status on the order, bumped by whoever gets there first - means the bar
 * marks a table Ready while the tandoor is still cooking, and the runner takes
 * half a table's food out.
 *
 * The order still holds the number everybody else reads: the bill screen, the
 * guest's phone and the reports never learn about lines.
 *
 * ---------------------------------------------------------------------------
 * Forwards by default
 * ---------------------------------------------------------------------------
 *
 * A bump only ever goes up the ladder, so a double tap on a hot line cannot
 * silently undo the station next to it. Going back is `recall()`: a separate
 * method, a separate permission, and a line in the activity log - which is
 * what §9 means by reopening being for authorised staff.
 *
 * ---------------------------------------------------------------------------
 * Bumping is what consumes stock
 * ---------------------------------------------------------------------------
 *
 * A dish moves no stock when it is *sold* - there is no count of Butter Naan -
 * so the flour and butter come off here, when the line reaches whichever rung
 * the outlet configured (§10). The kitchen is the right place for it: a dish
 * that was cooked and then comped, or sent back, or eaten by the staff, has
 * used its ingredients either way.
 */
class KitchenService
{
    public function __construct(
        private readonly TableOrderService $orders,
        private readonly RecipeService $recipes,
    ) {}

    /* ---------------------------------------------------------- the feed */

    /**
     * Outstanding tickets, oldest first.
     *
     * Oldest first and not newest: a kitchen works a queue, and a screen that
     * put the newest ticket at the top would bury the one that has been
     * waiting eleven minutes at the bottom of it.
     *
     * Every line of the order is loaded, not only this station's. The card
     * shows its own work and counts the rest, so a pass can tell that a table
     * is still waiting on the bar rather than assume the ticket is done.
     *
     * @return Collection<int, Order>
     */
    public function feed(?KitchenStation $station = null, ?string $type = null, int $limit = 60): Collection
    {
        return Order::query()
            ->onTheBoard()
            ->ofType($type)
            ->whereHas('items', fn (Builder $q) => $q->outstanding()->atStation($station?->id))
            ->with([
                // prep_minutes is in the select because the card's amber and red are
                // read off it - a station column list without it silently falls
                // back to the default window for every ticket on the board.
                'items' => fn ($q) => $q->with(['modifiers', 'kitchenStation:id,name,code,prep_minutes'])->orderBy('id'),
                'tableSession.table:id,name,code,floor_id',
                'tableSession.table.floor:id,name,code',
                'customer:id,name',
            ])
            ->orderByRaw('COALESCE(placed_at, created_at) asc')
            ->limit($limit)
            ->get();
    }

    /**
     * The numbers along the top of the screen.
     *
     * Counted from the lines rather than the orders, because with routing on
     * an order can be outstanding at two stations at once and "12 tickets"
     * would then mean something different on every screen showing it.
     *
     * @return array<string, int>
     */
    public function counts(?KitchenStation $station = null): array
    {
        $rows = OrderItem::query()
            ->outstanding()
            ->atStation($station?->id)
            ->whereHas('order', fn (Builder $q) => $q->onTheBoard())
            ->selectRaw('kitchen_status, COUNT(*) as total')
            ->groupBy('kitchen_status')
            ->pluck('total', 'kitchen_status');

        $counts = [];

        foreach (Order::KITCHEN_FLOW as $status) {
            $counts[$status] = (int) ($rows[$status] ?? 0);
        }

        $counts['late'] = $this->lateCount($station);

        return $counts;
    }

    /**
     * Lines the kitchen has had longer than the station allows.
     *
     * Timed from when the ticket was placed rather than from when somebody
     * accepted it: a ticket nobody has touched for twelve minutes is exactly
     * the one this number exists to shout about, and timing from acceptance
     * would hide it completely.
     *
     * Each station has its own window - a bar holding a drink order for six
     * minutes is late, a tandoor six minutes into a raan has barely started -
     * so the lines are counted a station at a time and added up. A handful of
     * small counts, rather than one query with a CASE nobody can read.
     */
    private function lateCount(?KitchenStation $station = null): int
    {
        $stations = $station
            ? collect([$station])
            : KitchenStation::query()->active()->get();

        $unrouted = OrderItem::query()
            ->outstanding()
            ->whereNull('kitchen_station_id')
            ->when($station, fn (Builder $q) => $q->whereRaw('1 = 0'))
            ->whereHas('order', fn (Builder $q) => $q
                ->onTheBoard()
                ->whereRaw('COALESCE(placed_at, created_at) < ?', [now()->subMinutes(15)]))
            ->count();

        $routed = $stations->sum(fn (KitchenStation $one) => OrderItem::query()
            ->outstanding()
            ->where('kitchen_station_id', $one->id)
            ->whereHas('order', fn (Builder $q) => $q
                ->onTheBoard()
                ->whereRaw('COALESCE(placed_at, created_at) < ?', [now()->subMinutes($one->prepMinutes())]))
            ->count());

        return (int) $routed + (int) $unrouted;
    }

    /* ------------------------------------------------------------- bumps */

    /**
     * Move one line up a rung.
     *
     * Locked, because two cooks on two screens both tapping the same line is
     * a Friday, not an edge case.
     */
    public function bumpLine(OrderItem $item, ?string $to = null): OrderItem
    {
        $line = DB::transaction(function () use ($item, $to) {
            /** @var OrderItem|null $line */
            $line = OrderItem::query()->whereKey($item->id)->lockForUpdate()->first();

            if ($line === null || ! $line->isKitchenLine()) {
                throw new RuntimeException('That line is not on a kitchen ticket.');
            }

            $this->applyToLine($line, $to ?? $line->nextKitchenStatus());

            $this->rollUp($line->order()->first());

            return $line->refresh();
        });

        // order_items has no shop_id of its own; the branch is the order's.
        $this->nudgeScreens($line->order?->shop_id, 'kitchen.line');

        return $line;
    }

    /**
     * Move everything this station is holding on one ticket.
     *
     * The button a cook actually presses. A ticket is a job, and bumping it
     * line by line when all six came off the same pass is six taps with
     * greasy hands.
     *
     * Lines already further along are left where they are rather than pulled
     * into step - a cook who bumped one dish early meant it.
     */
    public function bumpTicket(Order $order, ?KitchenStation $station = null, ?string $to = null): Order
    {
        $fresh = DB::transaction(function () use ($order, $station, $to) {
            $lines = $order->items()
                ->outstanding()
                ->atStation($station?->id)
                ->lockForUpdate()
                ->get();

            if ($lines->isEmpty()) {
                throw new RuntimeException('There is nothing outstanding on that ticket here.');
            }

            /*
             | One target for the whole ticket, taken from the least-advanced
             | line. Without it, a ticket whose lines had drifted apart would
             | take two taps to do what reads on screen as one step.
             */
            $target = $to ?? $this->nextFor($lines);

            $moved = 0;

            foreach ($lines as $line) {
                $moved += $this->applyToLine($line, $target, strict: false) ? 1 : 0;
            }

            /*
             | Nothing moved is an error, not a quiet success.
             |
             | With `strict` off every line that is already at or past the
             | target is skipped, which is right for a ticket whose lines have
             | drifted apart - but if that skips all of them, the caller asked
             | for something that did not happen. Reporting success there
             | would let a hand-posted backwards target read as a bump, and
             | would tell a cook their tap landed when it did not.
             */
            if ($moved === 0) {
                throw new RuntimeException(sprintf(
                    // Covers both "already there" and "already past it": in
                    // either case nothing here is waiting to make that move.
                    'Nothing on that ticket is waiting to go to %s here.',
                    Order::STATUSES[$target] ?? $target,
                ));
            }

            $this->rollUp($order);

            return $order->refresh();
        });

        $this->nudgeScreens($fresh->shop_id, 'kitchen.ticket');

        return $fresh;
    }

    /**
     * Tell every screen watching this branch that the pass has moved.
     *
     * After the transaction, never inside it: a bump that was rolled back
     * must not have announced itself.
     *
     * Deliberately fire-and-forget. With broadcasting off - the default, and
     * the right setting for any host that cannot run a long-lived process -
     * this is dispatched and discarded, and the kitchen display carries on
     * polling exactly as before. Nothing here may ever be load-bearing.
     */
    private function nudgeScreens(?int $shopId, string $reason): void
    {
        if ($shopId !== null && $shopId > 0) {
            ShopBoardChanged::dispatch($shopId, $reason);
        }
    }

    /**
     * Send a ticket back down the ladder (§9 - reopen).
     *
     * A real thing in a kitchen: a plate comes back, a station is bumped by
     * mistake. It is not a bump, though, and pretending it is would make the
     * preparation-time report meaningless - so it clears the stamps it
     * invalidates and always writes a line to the log.
     */
    public function recall(Order $order, string $to, ?KitchenStation $station = null, ?string $reason = null): Order
    {
        $target = array_search($to, Order::KITCHEN_FLOW, true);

        if ($target === false) {
            throw new RuntimeException('That is not a stage a kitchen ticket has.');
        }

        return DB::transaction(function () use ($order, $to, $target, $station, $reason) {
            $lines = $order->items()
                ->whereNotNull('kitchen_status')
                ->atStation($station?->id)
                ->lockForUpdate()
                ->get();

            if ($lines->isEmpty()) {
                throw new RuntimeException('That ticket has nothing to recall.');
            }

            $preparing = array_search(Order::PREPARING, Order::KITCHEN_FLOW, true);
            $ready = array_search(Order::READY, Order::KITCHEN_FLOW, true);

            $moved = 0;

            foreach ($lines as $line) {
                $at = array_search($line->kitchen_status, Order::KITCHEN_FLOW, true);

                if ($at === false || $at <= $target) {
                    continue;
                }

                $line->forceFill([
                    'kitchen_status' => $to,
                    // Stamps that no longer describe anything that happened.
                    'kitchen_started_at' => $target >= $preparing ? $line->kitchen_started_at : null,
                    'kitchen_ready_at' => $target >= $ready ? $line->kitchen_ready_at : null,
                ])->save();

                $moved++;
            }

            if ($moved === 0) {
                throw new RuntimeException('That ticket is not past '.(Order::STATUSES[$to] ?? $to).' here.');
            }

            /*
             | The order is pulled back by hand rather than through rollUp(),
             | which only ever moves a ticket forward. This is the one caller
             | allowed to go the other way, and it is why it holds a
             | permission of its own.
             */
            $order->forceFill([
                'status' => $to,
                'accepted_at' => $to === Order::PENDING ? null : $order->accepted_at,
                'ready_at' => in_array($to, [Order::READY, Order::SERVED], true) ? $order->ready_at : null,
                'served_at' => $to === Order::SERVED ? $order->served_at : null,
            ])->save();

            ActivityLog::record(
                'kitchen.recalled',
                sprintf(
                    'Recalled %s to %s%s%s',
                    $order->order_number,
                    Order::STATUSES[$to] ?? $to,
                    $station ? ' at '.$station->name : '',
                    filled($reason) ? ' — '.$reason : '',
                ),
                $order,
            );

            return $order->refresh();
        });
    }

    /* --------------------------------------------------------- internals */

    /**
     * Write one rung onto one line.
     *
     * `strict` is off for a whole-ticket bump, where a line already past the
     * target is skipped rather than treated as an error - see bumpTicket(),
     * which counts what actually moved and refuses a bump that moved nothing.
     *
     * @return bool whether this line actually changed
     */
    private function applyToLine(OrderItem $line, ?string $target, bool $strict = true): bool
    {
        if ($target === null) {
            if ($strict) {
                throw new RuntimeException('That line is already finished.');
            }

            return false;
        }

        $to = array_search($target, Order::KITCHEN_FLOW, true);

        if ($to === false) {
            throw new RuntimeException('That is not a stage a kitchen ticket has.');
        }

        $at = array_search($line->kitchen_status, Order::KITCHEN_FLOW, true);

        if ($at !== false && $to <= $at) {
            if ($strict) {
                throw new RuntimeException('A kitchen line only moves forward.');
            }

            return false;
        }

        $changes = ['kitchen_status' => $target];

        if ($target === Order::PREPARING && $line->kitchen_started_at === null) {
            $changes['kitchen_started_at'] = now();
        }

        if ($target === Order::READY && $line->kitchen_ready_at === null) {
            $changes['kitchen_ready_at'] = now();
            // A line bumped straight from Placed to Ready was still cooked;
            // without this its preparation time would read as zero.
            $changes['kitchen_started_at'] = $line->kitchen_started_at ?? now();
        }

        $line->forceFill($changes)->save();

        $this->consumeIfDue($line, $target);

        return true;
    }

    /**
     * Take the line's ingredients off the shelf, if it has now got far enough.
     *
     * Failures here are logged and swallowed on purpose. The food is cooked
     * and the cook is holding a pan: refusing to move the ticket because the
     * ingredient store could not be written would leave a kitchen unable to
     * work, and the stock figure is the less urgent of the two truths. The
     * line keeps a null `recipe_consumed_at`, so the next bump tries again.
     */
    private function consumeIfDue(OrderItem $line, string $rung): void
    {
        $shop = $line->order?->shop;

        if ($shop === null || ! $this->recipes->shouldConsumeAt($shop, $rung)) {
            return;
        }

        try {
            $this->recipes->consume($line);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * The rung a set of lines should move to together.
     *
     * The least-advanced one's next step, so a ticket whose lines have
     * drifted apart catches up rather than needing a tap per gap.
     *
     * @param  Collection<int, OrderItem>  $lines
     */
    private function nextFor(Collection $lines): ?string
    {
        $indexes = $lines
            ->map(fn (OrderItem $line) => array_search($line->kitchen_status, Order::KITCHEN_FLOW, true))
            ->filter(fn ($at) => $at !== false);

        if ($indexes->isEmpty()) {
            return null;
        }

        return Order::KITCHEN_FLOW[$indexes->min() + 1] ?? null;
    }

    /**
     * Set the order's status from its lines.
     *
     * The least-advanced line wins - see the class note - and the order only
     * ever moves forward here, so a station bumping its own work can never
     * drag a ticket backwards for everybody else.
     */
    public function rollUp(?Order $order): ?Order
    {
        if ($order === null) {
            return null;
        }

        $lines = $order->items()->whereNotNull('kitchen_status')->get();

        if ($lines->isEmpty()) {
            // A web order, or one placed before the kitchen module existed.
            // Its status is somebody else's to move.
            return $order;
        }

        $indexes = $lines
            ->map(fn (OrderItem $line) => array_search($line->kitchen_status, Order::KITCHEN_FLOW, true))
            ->filter(fn ($at) => $at !== false);

        if ($indexes->isEmpty()) {
            return $order;
        }

        $target = $indexes->min();
        $to = Order::KITCHEN_FLOW[$target];
        $at = array_search($order->status, Order::KITCHEN_FLOW, true);

        if ($at === false || $target <= $at) {
            return $order;
        }

        // Back through advance(), so the order's timestamps and its log entry
        // are written in exactly one place however the status came to move.
        return $this->orders->advance($order, $to);
    }
}
