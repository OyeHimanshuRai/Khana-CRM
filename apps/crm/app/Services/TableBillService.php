<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TableSession;
use App\Models\Warehouse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Settling a table (§6).
 *
 * ---------------------------------------------------------------------------
 * The bill is an invoice, not a new kind of document
 * ---------------------------------------------------------------------------
 *
 * Everything a dine-in bill needs - GST split by place of supply, a running
 * number, the ledger, the printed copy - InvoiceService already does, and does
 * identically for the counter. A second document type would be a second place
 * for the tax to be wrong.
 *
 * Stock is the one thing it does *not* do here, and that is deliberate: a dish
 * is marked made-to-order and moves none. A restaurant has no count of Butter
 * Naan, and "not enough stock" is never the right answer to somebody trying to
 * pay for food they have already eaten. See the migration, and §10's recipes.
 *
 * So this class does the part that is actually about tables: deciding which
 * of a sitting's lines go on which bill, and what happens to the table
 * afterwards.
 *
 * ---------------------------------------------------------------------------
 * Splitting
 * ---------------------------------------------------------------------------
 *
 * §6 asks for a split "by item, quantity or amount", and those are not three
 * features:
 *
 *   by amount    one bill, several payments - InvoiceService already
 *   by item      two bills, each with some of the lines
 *   by quantity  the same, with a line divided between them
 *
 * `order_items.settled_quantity` carries the second and third. A line is done
 * when it reaches its own quantity, whether that took one bill or three.
 */
class TableBillService
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly TableSessionService $sessions,
    ) {}

    /* ----------------------------------------------------------- reading */

    /**
     * Everything this sitting has eaten that nobody has paid for yet.
     *
     * Cancelled orders are left out: nothing was made and nothing is owed.
     * Everything else is in, including what the kitchen is still cooking -
     * a guest asking for the bill while the last dish is on the pass is a
     * normal Tuesday, and a bill that quietly omitted it would be short.
     *
     * @return Collection<int, OrderItem>
     */
    public function outstanding(TableSession $session): Collection
    {
        return OrderItem::query()
            ->whereIn('order_id', $session->orders()->where('status', '!=', Order::CANCELLED)->select('id'))
            ->with(['modifiers', 'order:id,order_number,status,placed_at', 'product:id,name,sku,tax_rate_id'])
            ->orderBy('order_id')
            ->orderBy('id')
            ->get()
            ->reject(fn (OrderItem $line) => $line->isSettled())
            ->values();
    }

    /**
     * What the screen puts along the top.
     *
     * The kitchen count is here on purpose and is not a refusal. Billing a
     * table whose last dish is still cooking is normal; billing one where
     * nothing has been accepted yet usually means somebody pressed the wrong
     * table. The screen says which it is and lets the person decide, because
     * this is the one moment a rule would get in the way of a guest leaving.
     *
     * @return array<string, mixed>
     */
    public function summary(TableSession $session): array
    {
        $lines = $this->outstanding($session);

        $unbilled = $lines->sum(fn (OrderItem $line) => $this->lineTotal($line));

        return [
            'lines' => $lines,
            'items' => (float) $lines->sum(fn (OrderItem $line) => $line->unsettledQuantity()),
            'unbilled' => round($unbilled, 2),
            'billed' => round((float) $session->invoices()->sum('grand_total'), 2),
            'paid' => round((float) $session->invoices()->sum('paid_total'), 2),
            'due' => round((float) $session->invoices()->sum('due_total'), 2),
            'in_kitchen' => $lines
                ->filter(fn (OrderItem $line) => in_array(
                    $line->kitchen_status,
                    [Order::PENDING, Order::CONFIRMED, Order::PREPARING],
                    true,
                ))
                ->sum(fn (OrderItem $line) => $line->unsettledQuantity()),
        ];
    }

    /** What an unsettled line comes to at the price it was ordered at. */
    private function lineTotal(OrderItem $line): float
    {
        return round((float) $line->unit_price * $line->unsettledQuantity(), 2);
    }

    /* ---------------------------------------------------------- settling */

    /**
     * Raise a bill for some or all of what the table owes.
     *
     * @param  array{
     *     lines?: array<int, array{order_item_id: int|string, quantity: float|string}>,
     *     customer?: \App\Models\Customer|null,
     *     warehouse?: Warehouse|null,
     *     payments?: array<int, array<string, mixed>>,
     *     invoice_discount?: float,
     *     invoice_discount_percent?: float,
     *     notes?: string|null,
     *     close?: bool,
     * }  $options  `lines` omitted means everything outstanding
     */
    public function settle(TableSession $session, array $options = []): Invoice
    {
        return DB::transaction(function () use ($session, $options) {
            /*
             | Locked for the duration. Two people settling the same table on
             | two tills - which is exactly what a split bill looks like from
             | the outside - would otherwise both read the same outstanding
             | lines and bill the guest twice for the same plate.
             */
            $locked = TableSession::query()->whereKey($session->id)->lockForUpdate()->first();

            if ($locked === null) {
                throw new RuntimeException('That sitting no longer exists.');
            }

            if ($locked->isClosed()) {
                throw new RuntimeException('That table has already been settled and cleared.');
            }

            $chosen = $this->chooseLines($session, $options['lines'] ?? null);

            if ($chosen->isEmpty()) {
                throw new RuntimeException('There is nothing left to bill on this table.');
            }

            $invoice = $this->invoices->create([
                'shop' => $session->shop,
                'table_session_id' => $session->id,
                'warehouse' => $options['warehouse'] ?? Warehouse::defaultFor($session->shop_id),
                'customer' => $options['customer'] ?? $session->customer,
                'channel' => Invoice::POS,
                /*
                 | The guest's own name, when the sitting has one and no
                 | account customer was attached. It is what the party called
                 | itself on the QR, and it is better on the bill than a blank.
                 */
                'walk_in_name' => ($options['customer'] ?? $session->customer)
                    ? null
                    : $session->guest_name,
                'walk_in_mobile' => ($options['customer'] ?? $session->customer)
                    ? null
                    : $session->guest_mobile,
                'items' => $chosen
                    ->map(fn (array $pick) => $this->invoiceLine($pick['line'], $pick['quantity']))
                    ->all(),
                'payments' => $options['payments'] ?? [],
                'invoice_discount' => (float) ($options['invoice_discount'] ?? 0),
                'invoice_discount_percent' => (float) ($options['invoice_discount_percent'] ?? 0),
                'notes' => $options['notes'] ?? null,
            ]);

            foreach ($chosen as $pick) {
                /** @var OrderItem $line */
                $line = $pick['line'];

                $line->forceFill([
                    'settled_quantity' => round((float) $line->settled_quantity + $pick['quantity'], 3),
                ])->save();
            }

            $this->afterSettling($session, $options['close'] ?? null);

            ActivityLog::record(
                'table_bill.raised',
                sprintf(
                    'Table %s billed %s — %s%s',
                    $session->table?->code ?? '?',
                    $invoice->number,
                    '₹'.number_format((float) $invoice->grand_total, 2),
                    $this->outstanding($session->fresh())->isEmpty() ? '' : ' (part of a split)',
                ),
                $invoice,
            );

            return $invoice;
        });
    }

    /**
     * Which lines this bill covers, and how much of each.
     *
     * A null request means everything, which is the ordinary case and must
     * not need the screen to enumerate it.
     *
     * @param  array<int, array{order_item_id: int|string, quantity: float|string}>|null  $request
     * @return Collection<int, array{line: OrderItem, quantity: float}>
     */
    private function chooseLines(TableSession $session, ?array $request): Collection
    {
        $outstanding = $this->outstanding($session)->keyBy('id');

        if ($request === null) {
            return $outstanding
                ->map(fn (OrderItem $line) => ['line' => $line, 'quantity' => $line->unsettledQuantity()])
                ->values();
        }

        $picked = collect();

        foreach ($request as $row) {
            $id = (int) ($row['order_item_id'] ?? 0);

            /** @var OrderItem|null $line */
            $line = $outstanding->get($id);

            if ($line === null) {
                /*
                 | Not "line not found": it may well exist and belong to
                 | another table, and the difference matters to whoever is
                 | standing at the till wondering why.
                 */
                throw new RuntimeException('One of those lines is not outstanding on this table.');
            }

            $wanted = round((float) ($row['quantity'] ?? 0), 3);

            if ($wanted <= 0) {
                continue;
            }

            if ($wanted > $line->unsettledQuantity() + 0.0005) {
                throw new RuntimeException(sprintf(
                    'Only %s of "%s" is left to bill.',
                    rtrim(rtrim(number_format($line->unsettledQuantity(), 3), '0'), '.'),
                    $line->title(),
                ));
            }

            $picked->push(['line' => $line, 'quantity' => $wanted]);
        }

        return $picked;
    }

    /**
     * One outstanding line, as InvoiceService wants it.
     *
     * The price is the one the guest was shown, add-ons included, and it is
     * passed as an explicit `unit_price` rather than left to the catalogue.
     * Re-pricing at the till would let a menu change between the order and the
     * bill charge somebody a figure they never saw.
     *
     * @return array<string, mixed>
     */
    private function invoiceLine(OrderItem $line, float $quantity): array
    {
        $extras = $line->modifiers->pluck('option_name')->filter()->implode(', ');

        return [
            'product_id' => $line->product_id,
            'quantity' => $quantity,
            'unit_price' => (float) $line->unit_price,
            /*
             | A menu price is what the guest pays. Saying so explicitly here
             | is what stops the tax being added a second time on top of a
             | figure that already contains it.
             */
            'price_includes_tax' => true,
            'name' => $line->title().($extras !== '' ? ' — '.$extras : ''),
            'note' => $line->note,
        ];
    }

    /**
     * What happens to the table once a bill has been raised.
     *
     * Fully paid up means the party is leaving, so the sitting closes and the
     * table goes to `cleaning` - never straight to `available`, because a plan
     * that offered a table before anybody wiped it down would be lying about
     * the room.
     *
     * A partial settle only marks the sitting `billed`, which stops it taking
     * new orders. Somebody who has paid their half should not be able to add a
     * dessert to a bill that has already been printed.
     */
    private function afterSettling(TableSession $session, ?bool $close): void
    {
        $remaining = $this->outstanding($session->fresh());

        if ($close === false) {
            return;
        }

        if ($remaining->isEmpty()) {
            $this->sessions->close($session->fresh(), 'Settled');

            return;
        }

        $this->sessions->bill($session->fresh());
    }

    /* ------------------------------------------------- moving and merging */

    /**
     * Move one ticket to another table (§6).
     *
     * The order number is deliberately left alone. It embeds the table it was
     * sent from and it has already been called out across a kitchen; renumbering
     * it would mean the slip on the pass and the screen no longer agree, which
     * is how a plate goes to the wrong room.
     */
    public function moveOrder(Order $order, TableSession $into): Order
    {
        if (! $order->isDineIn()) {
            throw new RuntimeException('Only a dine-in ticket belongs to a table.');
        }

        if ((int) $order->table_session_id === (int) $into->id) {
            throw new RuntimeException('That ticket is already on this table.');
        }

        if ($into->isClosed()) {
            throw new RuntimeException('That table has been settled and cleared.');
        }

        if ($order->shop_id !== $into->shop_id) {
            throw new RuntimeException('A ticket cannot move to a table in another branch.');
        }

        return DB::transaction(function () use ($order, $into) {
            $from = $order->tableSession;

            $order->forceFill(['table_session_id' => $into->id])->save();

            ActivityLog::record(
                'table_bill.moved',
                sprintf(
                    'Order %s moved from table %s to table %s',
                    $order->order_number,
                    $from?->table?->code ?? '?',
                    $into->table?->code ?? '?',
                ),
                $order,
            );

            return $order->refresh();
        });
    }

    /**
     * Put two tables on one bill (§6).
     *
     * Every ticket moves; the emptied sitting closes and its table is freed.
     * The direction matters and is the caller's to choose - the party sat down
     * somewhere, and that is the table the runner will look for.
     */
    public function merge(TableSession $from, TableSession $into): TableSession
    {
        if ((int) $from->id === (int) $into->id) {
            throw new RuntimeException('A table cannot be merged into itself.');
        }

        if ($from->shop_id !== $into->shop_id) {
            throw new RuntimeException('Tables in two branches cannot be merged.');
        }

        if ($into->isClosed()) {
            throw new RuntimeException('The table being merged into has been settled and cleared.');
        }

        /*
         | A table with a bill already raised against it cannot be folded into
         | another: that invoice names a sitting, and moving its lines
         | afterwards would leave a filed document describing food that is now
         | on somebody else's table.
         */
        if ($from->invoices()->exists()) {
            throw new RuntimeException(
                'That table has already been billed. Settle what is left on it separately.'
            );
        }

        return DB::transaction(function () use ($from, $into) {
            $moved = 0;

            foreach ($from->orders()->get() as $order) {
                $order->forceFill(['table_session_id' => $into->id])->save();
                $moved++;
            }

            // Anything picked but not yet sent goes too, or the guests would
            // watch their choices vanish when the tables were pushed together.
            $from->cartItems()->update(['table_session_id' => $into->id]);

            $this->sessions->close($from->fresh(), 'Merged into table '.($into->table?->code ?? '?'));

            ActivityLog::record(
                'table_bill.merged',
                sprintf(
                    'Table %s merged into table %s — %d ticket%s',
                    $from->table?->code ?? '?',
                    $into->table?->code ?? '?',
                    $moved,
                    $moved === 1 ? '' : 's',
                ),
                $into,
            );

            return $into->fresh();
        });
    }

    /**
     * Tables a sitting could be moved or merged onto.
     *
     * Open sittings only, and never the one being moved. A closed table has
     * nobody at it, and merging onto it would invent a party.
     *
     * @return Collection<int, TableSession>
     */
    public function mergeTargets(TableSession $session): Collection
    {
        return TableSession::query()
            ->live()
            ->whereKeyNot($session->id)
            ->with('table:id,name,code,floor_id', 'table.floor:id,name')
            ->get()
            ->sortBy(fn (TableSession $other) => $other->table?->code)
            ->values();
    }

    /**
     * Free a table whose party left without paying anything.
     *
     * Rare and real - a walkout, or a party that only had water. Refused once
     * anything has been billed, because a sitting with an invoice against it
     * is an accounting record and not a table to be tidied away.
     */
    public function abandon(TableSession $session, string $reason): TableSession
    {
        if ($session->invoices()->exists()) {
            throw new RuntimeException('That table has a bill against it. It cannot be written off from here.');
        }

        $unbilled = $this->summary($session)['unbilled'];

        $closed = $this->sessions->close($session, $reason);

        ActivityLog::record(
            'table_bill.abandoned',
            sprintf(
                'Table %s cleared unpaid — ₹%s — %s',
                $session->table?->code ?? '?',
                number_format((float) $unbilled, 2),
                $reason,
            ),
            $session,
        );

        return $closed;
    }

    /**
     * The tables the counter can currently bill.
     *
     * @return Collection<int, TableSession>
     */
    public function openTables(): Collection
    {
        return TableSession::query()
            ->openOrBilled()
            ->with([
                'table:id,name,code,floor_id,status',
                'table.floor:id,name,code',
                'customer:id,name',
            ])
            ->withCount('orders')
            ->get()
            ->sortBy(fn (TableSession $session) => $session->table?->code ?? '')
            ->values();
    }
}
