<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Events\ShopBoardChanged;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TableCartItem;
use App\Models\TableSession;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sending a table's cart to the kitchen (§3.7 - §3.9).
 *
 * The sibling of OrderService, not a replacement for it. That one places a
 * web order: it takes a customer, reserves stock FEFO and collects payment.
 * This one places a dine-in order: no customer need exist, nothing is
 * reserved because the kitchen cooks to order, and nobody pays until the
 * table asks for the bill.
 *
 * They write the same table on purpose - see the migration - so one report
 * and one POS screen see every channel.
 *
 * ---------------------------------------------------------------------------
 * What is snapshotted, and why
 * ---------------------------------------------------------------------------
 *
 * The cart holds ids; the order holds names and prices. A menu gets re-priced
 * and re-worded, and a ticket sent at eight has to keep saying what it said -
 * exactly as an invoice line copies its tax rate rather than pointing at it.
 * The ids are kept alongside so a report can still group by dish and by
 * add-on while those rows exist.
 */
class TableOrderService
{
    public function __construct(
        private readonly TableCartService $cart,
        private readonly KitchenRouter $router,
    ) {}

    /**
     * Send everything in the cart.
     *
     * One order per send, and a table may send several during a sitting -
     * §3.11 - which is what makes "another round of drinks" a second ticket
     * for the kitchen and one line on the same bill.
     *
     * @param  array{name?: string|null, mobile?: string|null, note?: string|null}  $guest
     */
    public function place(TableSession $session, array $guest = []): Order
    {
        if (! $session->isOpen()) {
            throw new RuntimeException(
                'The bill for this table has been raised. Please ask a member of staff.'
            );
        }

        $order = DB::transaction(function () use ($session, $guest) {
            /*
             | Locked for the duration. Two phones at the same table tapping
             | Order in the same second would otherwise both read the cart,
             | both write an order, and the kitchen would cook everything
             | twice.
             */
            $locked = TableSession::query()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->status !== TableSession::OPEN) {
                throw new RuntimeException('This sitting is no longer taking orders.');
            }

            $lines = $session->cartItems()
                ->with(['product.taxRate', 'variant'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($lines->isEmpty()) {
                throw new RuntimeException('There is nothing in the cart yet.');
            }

            /*
             | Re-checked here and not only when the line was added. A dish
             | can sell out between a guest choosing it and tapping Order, and
             | this is the check that matters - the other one is a courtesy.
             */
            $blocked = $lines->reject(fn (TableCartItem $item) => $item->isOrderable());

            if ($blocked->isNotEmpty()) {
                throw new RuntimeException(sprintf(
                    '%s is no longer available. Remove it and send the rest.',
                    $blocked->first()->title(),
                ));
            }

            $order = $this->open($session, $guest);

            $subtotal = 0.0;

            foreach ($lines as $line) {
                $subtotal += $this->commitLine($order, $line);
            }

            $order->forceFill([
                'subtotal' => round($subtotal, 2),
                /*
                 | No discount and no delivery charge on a dine-in ticket: a
                 | table's discount is applied once, on the bill, by whoever
                 | is authorised to give it. Applying it per order would let
                 | four rounds of drinks take four discounts.
                 */
                'grand_total' => round($subtotal, 2),
            ])->save();

            // Everything they had picked is now on a ticket.
            $this->cart->clear($session);

            $session->forceFill(['last_activity_at' => now()])->save();

            ActivityLog::record(
                'table_order.placed',
                sprintf(
                    'Table %s sent order %s — %d item%s, ₹%s',
                    $session->table?->code ?? '?',
                    $order->order_number,
                    $lines->sum('quantity'),
                    $lines->sum('quantity') === 1 ? '' : 's',
                    number_format((float) $order->grand_total, 2),
                ),
                $order,
            );

            return $order->refresh()->load('items.modifiers');
        });

        /*
         | Nudge the kitchen screen (§8, §9).
         |
         | After the transaction, never inside it: a ticket that was rolled
         | back must not have announced itself to a pass that will never
         | receive it.
         |
         | Discarded entirely when broadcasting is off, which is the default.
         | The kitchen display polls regardless - this only saves it the wait.
         | See App\Events\ShopBoardChanged.
         */
        ShopBoardChanged::dispatch((int) $order->shop_id, 'order.placed');

        return $order;
    }

    /** The order row itself, before any lines are on it. */
    private function open(TableSession $session, array $guest): Order
    {
        $order = new Order([
            'shop_id' => $session->shop_id,
            'table_session_id' => $session->id,
            'order_type' => Order::DINE_IN,
            // A sitting may name itself once and every later ticket inherits
            // it, so a guest is not asked their name with every round.
            'guest_name' => $guest['name'] ?? $session->guest_name,
            'guest_mobile' => $guest['mobile'] ?? $session->guest_mobile,
            'customer_id' => $session->customer_id,
            'order_number' => $this->nextNumber($session),
            'status' => Order::PENDING,
            /*
             | Null, not `cod`. A table has not chosen how it will pay and
             | will not until the bill is asked for; writing a guess here
             | would show up in the payment-method report as a fact.
             */
            'payment_method' => null,
            'payment_status' => Order::PAYMENT_PENDING,
            'customer_note' => $guest['note'] ?? null,
            'placed_at' => now(),
        ]);

        $order->save();

        return $order;
    }

    /**
     * Write one cart line onto the order, and return what it came to.
     *
     * Names and prices are copied. See the class note.
     */
    private function commitLine(Order $order, TableCartItem $line): float
    {
        $product = $line->product;
        $options = $line->options();

        $base = $line->variant
            ? (float) $line->variant->price
            : (float) $product->channelPriceFor('dine_in', $order->shop_id);

        $unit = round($base + (float) $options->sum('price'), 2);
        $total = round($unit * max(1, (int) $line->quantity), 2);

        /** @var OrderItem $item */
        $item = $order->items()->create([
            'product_id' => $product->id,
            'product_variant_id' => $line->variant?->id,
            'product_name' => $product->name,
            'variant_name' => $line->variant?->name,
            'sku' => $line->variant?->sku ?: $product->sku,
            'quantity' => $line->quantity,
            'unit_price' => $unit,
            'line_total' => $total,
            'note' => $line->note,
            /*
             | Routed here and copied onto the line, not looked up when the
             | KDS draws it (§9). Re-filing a dish at nine must not move a
             | ticket that is already on a pass, and a preparation-time report
             | run next month has to say which station actually cooked it.
             |
             | Null where the branch runs no stations at all - a one-room
             | kitchen that never set any of this up still sees everything.
             */
            'kitchen_station_id' => $this->router->stationIdFor($product, $order->shop_id),
            /*
             | On the board the moment it is sent. This is what puts the line
             | in the kitchen's feed at all, and what tells the rollup that
             | this order's status is the kitchen's to move.
             */
            'kitchen_status' => Order::PENDING,
            /*
             | Nothing reserved. A kitchen cooks to order and the stock it
             | consumes comes off through recipes when the dish is made - see
             | the inventory work still to come. Reserving a plate of food the
             | way a warehouse reserves a box would be a fiction.
             */
            'reserved_batches' => null,
        ]);

        foreach ($options as $option) {
            $item->modifiers()->create([
                'modifier_option_id' => $option->id,
                'modifier_name' => $option->modifier?->name ?? '',
                'option_name' => $option->name,
                'price' => (float) $option->price,
            ]);
        }

        return $total;
    }

    /**
     * The order's number, unique within the branch.
     *
     * Short and human, because it is called out across a kitchen: the table
     * code, then which ticket it is. "GF-04/2" is table 4's second ticket and
     * reads as that to everybody who hears it.
     *
     * ---------------------------------------------------------------------
     * Counted per table, not per sitting
     * ---------------------------------------------------------------------
     *
     * It used to count the sitting's own rounds, which is the number a waiter
     * would say out loud - and which is not unique. `orders` is unique on
     * (shop_id, order_number) for ever, while a sitting's rounds restart at
     * one every time a new party sits down. So the second party at table 4
     * sent "GF-04/1", the index refused it, and the guest got a 500 from the
     * cart with their food never reaching the kitchen. Every table in the
     * building broke on its second sitting of the day.
     *
     * Counting the table's own tickets keeps the number just as short and
     * just as sayable, and it never restarts. The first sitting at a fresh
     * table still reads GF-04/1, GF-04/2; the next party carries on at
     * GF-04/3, which is also the more useful answer on a pass where two
     * parties have sat at that table this evening.
     *
     * The walk forward covers the gaps. An order moved to another table keeps
     * the number it was given, so a plain count can land on something already
     * taken; stepping past what exists is cheaper than a scheme that could
     * not. Concurrency is already handled - send() holds the sitting's row
     * for the whole transaction, and two parties never share a table code.
     */
    private function nextNumber(TableSession $session): string
    {
        $code = $session->table?->code ?? 'T';
        $shopId = (int) $session->shop_id;

        $taken = fn (string $number) => Order::query()
            ->withoutGlobalScopes()
            ->where('shop_id', $shopId)
            ->where('order_number', $number)
            ->exists();

        // Asked of the sittings rather than by matching the number's text: a
        // table code is an editable field, and a LIKE against one carrying an
        // underscore would quietly match half the building.
        $round = Order::query()
            ->withoutGlobalScopes()
            ->where('shop_id', $shopId)
            ->whereIn('table_session_id', TableSession::query()
                ->withoutGlobalScopes()
                ->where('restaurant_table_id', $session->restaurant_table_id)
                ->select('id'))
            ->count() + 1;

        while ($taken($code.'/'.$round)) {
            $round++;
        }

        return sprintf('%s/%d', $code, $round);
    }

    /* ----------------------------------------------------------- the ladder */

    /**
     * Move a ticket to the next stage, or to a named one.
     *
     * The timestamps are written here rather than derived from a log, because
     * §9 wants a preparation-time report and "when did this turn ready" should
     * be one column.
     *
     * Only ever forwards. A ticket sent back a stage is a real thing in a
     * kitchen, but it is a correction with a reason attached, and quietly
     * allowing it here would make the preparation-time report meaningless.
     */
    public function advance(Order $order, ?string $to = null): Order
    {
        $to ??= $order->nextKitchenStatus();

        if ($to === null) {
            throw new RuntimeException('That ticket is already finished.');
        }

        $flow = Order::KITCHEN_FLOW;
        $from = array_search($order->status, $flow, true);
        $target = array_search($to, $flow, true);

        if ($target === false) {
            throw new RuntimeException('That is not a stage a kitchen ticket has.');
        }

        if ($from !== false && $target <= $from) {
            throw new RuntimeException('A ticket only moves forward.');
        }

        $stamps = [
            Order::CONFIRMED => 'accepted_at',
            Order::READY => 'ready_at',
            Order::SERVED => 'served_at',
        ];

        $changes = ['status' => $to];

        if (isset($stamps[$to]) && $order->{$stamps[$to]} === null) {
            $changes[$stamps[$to]] = now();
        }

        $order->forceFill($changes)->save();

        ActivityLog::record(
            'table_order.'.$to,
            sprintf('Order %s → %s', $order->order_number, $order->statusLabel()),
            $order,
        );

        return $order;
    }
}
