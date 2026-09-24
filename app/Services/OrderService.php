<?php

namespace App\Services;

use App\Mail\OrderPlacedMail;
use App\Models\ActivityLog;
use App\Models\Address;
use App\Models\Batch;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The checkout-to-delivery state machine for a storefront order.
 *
 * Built entirely from StockService's and InvoiceService's own primitives -
 * this class never touches product_stocks directly and never prices or
 * taxes a line itself. Stock is *reserved* at place() (a hold, no ledger
 * row) and only actually *issued* at markPaid(), when InvoiceService raises
 * the real Invoice - see the Order model's docblock for why the two are
 * kept apart.
 */
class OrderService
{
    public function __construct(
        private readonly CartService $cart,
        private readonly StockService $stock,
        private readonly CouponService $coupons,
        private readonly InvoiceService $invoices,
    ) {}

    /**
     * Place an order from the customer's current cart.
     *
     * @param  array{
     *     address_id?: int|null,
     *     address?: array<string, mixed>|null,
     *     payment_method: string,
     *     coupon_code?: string|null,
     *     customer_note?: string|null,
     * }  $data
     */
    /**
     * Take an order that did not come from a cart (§2, §21).
     *
     * The path for an aggregator or a captain's app: explicit lines, no
     * session, no customer account. `place()` above cannot serve it - it
     * reads the signed-in customer's cart, and an aggregator has neither.
     *
     * ----------------------------------------------------------------------
     * Prices come from the menu, never from the caller
     * ----------------------------------------------------------------------
     *
     * The payload says which dish and how many. What it costs is looked up
     * here, every time. An integrator that could name its own price could
     * bill a restaurant's customer a rupee for a biryani, and "they sent it
     * in the payload" is not a defence anybody wants to make.
     *
     * @param  array{
     *     shop_id: int|null,
     *     order_type: string,
     *     guest_name?: string|null,
     *     guest_mobile?: string|null,
     *     customer_note?: string|null,
     *     items: array<int, array{product_id: int, product_variant_id?: int|null, quantity: float, note?: string|null}>,
     * }  $data
     *
     * @throws RuntimeException
     */
    public function placeExternal(array $data): Order
    {
        $shopId = $data['shop_id'] ?? null;

        if ($shopId === null) {
            throw new RuntimeException('This token is not tied to a single branch, so there is nowhere to file the order.');
        }

        if (empty($data['items'])) {
            throw new RuntimeException('An order needs at least one item.');
        }

        $router = app(KitchenRouter::class);

        return DB::transaction(function () use ($data, $shopId, $router) {
            $order = Order::create([
                'shop_id' => $shopId,
                'order_type' => $data['order_type'],
                'guest_name' => $data['guest_name'] ?? null,
                'guest_mobile' => $data['guest_mobile'] ?? null,
                'customer_note' => $data['customer_note'] ?? null,
                'order_number' => $this->nextExternalNumber($shopId),
                'status' => Order::PENDING,
                /*
                 | Null, not `cod`. How an aggregator settles with a
                 | restaurant is between them; writing a guess here would show
                 | up in the payment-method report as a fact.
                 */
                'payment_method' => null,
                'payment_status' => Order::PAYMENT_PENDING,
                'placed_at' => now(),
                'subtotal' => 0,
                'grand_total' => 0,
            ]);

            $subtotal = 0.0;

            foreach ($data['items'] as $line) {
                $product = Product::query()->sellable()->find($line['product_id']);

                if ($product === null) {
                    throw new RuntimeException('Item '.$line['product_id'].' is not on this menu.');
                }

                $variant = isset($line['product_variant_id'])
                    ? $product->variants()->find($line['product_variant_id'])
                    : null;

                // The menu's own price, for the channel the order came in on.
                $unit = $variant
                    ? (float) $variant->price
                    : (float) $product->channelPriceFor(
                        $data['order_type'] === Order::DINE_IN ? 'dine_in' : 'takeaway',
                        $shopId,
                    );

                $quantity = max(0.001, (float) $line['quantity']);
                $total = round($unit * $quantity, 2);

                $order->items()->create([
                    'product_id' => $product->id,
                    'product_variant_id' => $variant?->id,
                    'product_name' => $product->name,
                    'variant_name' => $variant?->name,
                    'sku' => $variant?->sku ?: $product->sku,
                    'quantity' => $quantity,
                    'unit_price' => $unit,
                    'line_total' => $total,
                    'note' => $line['note'] ?? null,
                    // Routed and copied onto the line, exactly as a table
                    // order is - see TableOrderService for why it is not
                    // looked up when the kitchen screen draws it.
                    'kitchen_station_id' => $router->stationIdFor($product, $shopId),
                    'kitchen_status' => Order::PENDING,
                ]);

                $subtotal += $total;
            }

            $order->forceFill([
                'subtotal' => round($subtotal, 2),
                'grand_total' => round($subtotal, 2),
            ])->save();

            ActivityLog::record(
                'order.api_placed',
                sprintf('Order %s taken through the API - %s', $order->order_number, number_format($subtotal, 2)),
                $order,
            );

            return $order->refresh()->load('items');
        });
    }

    /**
     * The next number for an order that came in through the API.
     *
     * Prefixed so it is obvious on a kitchen screen where a ticket came from:
     * a cook looking at API-0042 knows nobody in the building typed it.
     */
    private function nextExternalNumber(int $shopId): string
    {
        $last = Order::query()
            ->withoutGlobalScopes()
            ->where('shop_id', $shopId)
            ->where('order_number', 'like', 'API-%')
            ->max('id');

        return 'API-'.str_pad((string) ((int) $last + 1), 4, '0', STR_PAD_LEFT);
    }

    public function place(Shop $shop, Customer $customer, Request $request, array $data): Order
    {
        $cartContents = $this->cart->contents($shop, $customer, $request);
        $lines = $cartContents['items'];

        if ($lines->isEmpty()) {
            throw new RuntimeException('Your cart is empty.');
        }

        $address = $this->resolveAddress($shop, $customer, $data);
        $warehouse = Warehouse::defaultFor($shop->id)
            ?? throw new RuntimeException('This shop has no warehouse to fulfil orders from.');

        return DB::transaction(function () use ($shop, $customer, $request, $lines, $address, $warehouse, $data) {
            $subtotal = round((float) $lines->sum('line_total'), 2);

            $coupon = null;
            $discount = 0.0;
            $couponCode = null;

            if (! blank($data['coupon_code'] ?? null)) {
                $coupon = $this->coupons->validate($shop, trim((string) $data['coupon_code']), $customer, $subtotal);
                $discount = $this->coupons->discountFor($coupon, $subtotal);
                $couponCode = $coupon->code;
            }

            $grandTotal = round($subtotal - $discount, 2);

            $order = new Order([
                'shop_id' => $shop->id,
                'customer_id' => $customer->id,
                'warehouse_id' => $warehouse->id,
                'status' => Order::PENDING,
                'payment_method' => $data['payment_method'],
                'payment_status' => Order::PAYMENT_PENDING,
                'subtotal' => $subtotal,
                'discount_total' => $discount,
                'coupon_id' => $coupon?->id,
                'coupon_code' => $couponCode,
                'shipping_amount' => 0,
                'grand_total' => $grandTotal,
                'ship_recipient_name' => $address['recipient_name'],
                'ship_mobile' => $address['mobile'],
                'ship_address_line1' => $address['address_line1'],
                'ship_address_line2' => $address['address_line2'] ?? null,
                'ship_village' => $address['village'] ?? null,
                'ship_taluka' => $address['taluka'] ?? null,
                'ship_district' => $address['district'] ?? null,
                'ship_city' => $address['city'] ?? null,
                'ship_state' => $address['state'] ?? null,
                'ship_pincode' => $address['pincode'] ?? null,
                'customer_note' => $data['customer_note'] ?? null,
                'placed_at' => now(),
            ]);

            // order_number is derived from the row's own id, so a
            // collision-safe placeholder holds the NOT NULL column until
            // the insert has happened.
            $order->order_number = 'PENDING-'.Str::random(20);
            $order->save();
            $order->forceFill(['order_number' => Order::nextNumber($shop, $order->id)])->save();

            foreach ($lines as $line) {
                /** @var Product $product */
                $product = $line['product'];
                $quantity = (float) $line['quantity'];

                $allocations = $this->reserveForLine($product, $quantity, $warehouse, $shop);

                $order->items()->create([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'sku' => $product->sku,
                    'quantity' => $quantity,
                    'unit_price' => $line['unit_price'],
                    'line_total' => $line['line_total'],
                    'reserved_batches' => $allocations,
                ]);
            }

            if ($coupon) {
                CouponRedemption::query()->create([
                    'coupon_id' => $coupon->id,
                    'shop_id' => $shop->id,
                    'customer_id' => $customer->id,
                    'order_id' => $order->id,
                    'discount_amount' => $discount,
                ]);
            }

            $this->cart->clear($shop, $customer, $request);

            ActivityLog::record(
                'order.placed',
                sprintf('%s placed order %s (₹%s)', $customer->name, $order->order_number, number_format($grandTotal, 2)),
                $order,
            );

            $order = $order->refresh()->load('items');

            if (filled($customer->email)) {
                Mail::to($customer->email)->queue(new OrderPlacedMail($order));
            }

            return $order;
        });
    }

    /** The shop has seen and accepted the order. No stock/invoice effect. */
    public function confirm(Order $order, User $staff): Order
    {
        if (! $order->isPending()) {
            throw new RuntimeException('Only a pending order can be confirmed.');
        }

        $order->forceFill([
            'status' => Order::CONFIRMED,
            'confirmed_by' => $staff->id,
            'confirmed_at' => now(),
        ])->save();

        ActivityLog::record('order.confirmed', "Confirmed order {$order->order_number}", $order);

        return $order;
    }

    /** Move an already-confirmed order through packing/shipped. */
    public function updateStatus(Order $order, string $status): Order
    {
        $allowed = [Order::PACKING, Order::SHIPPED];

        if (! in_array($status, $allowed, true)) {
            throw new RuntimeException('That is not a valid status to set directly.');
        }

        if (in_array($order->status, [Order::PENDING, Order::CANCELLED, Order::DELIVERED], true)) {
            throw new RuntimeException("An order that is {$order->statusLabel()} cannot move to that status.");
        }

        $order->forceFill(['status' => $status])->save();

        return $order;
    }

    /**
     * Mark an order paid - the moment the shop can state what was actually
     * collected, whether that is cash on delivery or a manually verified
     * online payment. This is where the real Invoice is raised.
     *
     * @param  array{amount: float, method: string, transaction_ref?: string|null}  $payment
     */
    public function markPaid(Order $order, User $staff, array $payment): Order
    {
        if ($order->isCancelled()) {
            throw new RuntimeException('A cancelled order cannot be marked paid.');
        }

        if ($order->hasInvoice()) {
            throw new RuntimeException('This order has already been invoiced.');
        }

        return DB::transaction(function () use ($order, $staff, $payment) {
            $shop = $order->shop;
            $warehouse = $order->warehouse;

            foreach ($order->items as $item) {
                $this->releaseLine($item, $shop);
            }

            $invoiceItems = $order->items->flatMap(function ($item) {
                $allocations = $item->reserved_batches;

                if (empty($allocations)) {
                    return [[
                        'product_id' => $item->product_id,
                        'quantity' => (float) $item->quantity,
                        'unit_price' => (float) $item->unit_price,
                        'price_includes_tax' => true,
                    ]];
                }

                return collect($allocations)->map(fn (array $a) => [
                    'product_id' => $item->product_id,
                    'batch_id' => $a['batch_id'] ?? null,
                    'quantity' => (float) $a['quantity'],
                    'unit_price' => (float) $item->unit_price,
                    'price_includes_tax' => true,
                ]);
            })->all();

            $invoice = $this->invoices->create([
                'shop' => $shop,
                'warehouse' => $warehouse,
                'customer' => $order->customer,
                'channel' => Invoice::ONLINE,
                'items' => $invoiceItems,
                'invoice_discount' => (float) $order->discount_total,
                'payments' => [[
                    'amount' => $payment['amount'],
                    'method' => $payment['method'],
                    'transaction_ref' => $payment['transaction_ref'] ?? null,
                ]],
            ]);

            $order->forceFill([
                'invoice_id' => $invoice->id,
                'payment_status' => Order::PAYMENT_PAID,
                'paid_by' => $staff->id,
                'paid_at' => now(),
                'status' => $order->status === Order::PENDING ? Order::CONFIRMED : $order->status,
            ])->save();

            ActivityLog::record(
                'order.paid',
                "Marked order {$order->order_number} paid, raised invoice {$invoice->number}",
                $order,
            );

            return $order->refresh();
        });
    }

    /** Refuses unless a real Invoice exists - Invoice must precede Delivery. */
    public function markDelivered(Order $order): Order
    {
        if (! $order->hasInvoice()) {
            throw new RuntimeException('An order can only be marked delivered once it has been invoiced.');
        }

        $order->forceFill([
            'status' => Order::DELIVERED,
            'delivered_at' => now(),
        ])->save();

        return $order;
    }

    /**
     * Cancel an order, releasing whatever stock is still only reserved, or
     * reversing the Invoice if one has already been raised.
     */
    public function cancel(Order $order, string $reason, User $staff): Order
    {
        if (trim($reason) === '') {
            throw new RuntimeException('Cancelling an order needs a reason.');
        }

        if ($order->isCancelled()) {
            throw new RuntimeException('That order is already cancelled.');
        }

        return DB::transaction(function () use ($order, $reason, $staff) {
            if ($order->hasInvoice()) {
                $this->invoices->cancel($order->invoice, $reason);
            } else {
                $shop = $order->shop;

                foreach ($order->items as $item) {
                    $this->releaseLine($item, $shop);
                }
            }

            $order->forceFill([
                'status' => Order::CANCELLED,
                'cancelled_by' => $staff->id,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ])->save();

            ActivityLog::record('order.cancelled', "Cancelled order {$order->order_number}: {$reason}", $order);

            return $order->refresh();
        });
    }

    /* ------------------------------------------------------------ internals */

    /**
     * Reserve stock for one cart line, allocated FEFO exactly like a real
     * sale would be, and return the allocation to freeze onto the order
     * item.
     *
     * @return array<int, array{batch_id: int|null, warehouse_id: int, quantity: float}>
     */
    private function reserveForLine(Product $product, float $quantity, Warehouse $warehouse, Shop $shop): array
    {
        /*
         | A dish is made to order, so there is nothing to hold.
         |
         | Reserving a plate of food the way a warehouse reserves a box would
         | be a fiction, and the availability check below would refuse an
         | online biryani order on a night the kitchen is perfectly able to
         | cook it. An empty allocation also means releaseLine() has nothing
         | to give back if the order is cancelled, which is correct.
         |
         | Whether the kitchen can still make it is the sold-out tap and the
         | serving window, which the menu already enforces.
         */
        if ($product->is_made_to_order) {
            return [];
        }

        $plan = $this->stock->planPick($product, $quantity, $warehouse, $shop->id);
        $picked = (float) $plan->sum('quantity');

        if ($picked + 0.0005 < $quantity && ! $shop->allow_negative_stock) {
            throw new RuntimeException(sprintf(
                'Only %s of "%s" is available right now.',
                rtrim(rtrim(number_format($picked, 3, '.', ''), '0'), '.') ?: '0',
                $product->name,
            ));
        }

        if ($plan->isEmpty()) {
            // Nothing on the shelf and the shop allows it: reserve against
            // no particular lot, same as a negative-stock sale would.
            $this->stock->reserve($product, $quantity, $warehouse, null, $shop->id);

            return [['batch_id' => null, 'warehouse_id' => $warehouse->id, 'quantity' => $quantity]];
        }

        $allocations = $plan->map(fn (array $row) => [
            'batch' => $row['batch'],
            'quantity' => (float) $row['quantity'],
        ])->all();

        $shortfall = $quantity - $picked;

        if ($shortfall > 0.0005) {
            $allocations[count($allocations) - 1]['quantity'] += $shortfall;
        }

        $result = [];

        foreach ($allocations as $allocation) {
            /** @var Batch|null $batch */
            $batch = $allocation['batch'];

            $this->stock->reserve($product, $allocation['quantity'], $warehouse, $batch, $shop->id);

            $result[] = [
                'batch_id' => $batch?->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => $allocation['quantity'],
            ];
        }

        return $result;
    }

    /** Release exactly the reservation an order item was given at placement. */
    private function releaseLine(OrderItem $item, Shop $shop): void
    {
        $allocations = $item->reserved_batches ?? [];
        $product = $item->product;

        if ($product === null) {
            return;
        }

        foreach ($allocations as $allocation) {
            $warehouse = Warehouse::allShops()->find($allocation['warehouse_id']);

            if ($warehouse === null) {
                continue;
            }

            $batch = $allocation['batch_id'] ? Batch::allShops()->find($allocation['batch_id']) : null;

            $this->stock->release($product, (float) $allocation['quantity'], $warehouse, $batch, $shop->id);
        }
    }

    /**
     * Resolve the shipping address for a checkout: an existing address-book
     * row, or an inline address submitted with the order.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolveAddress(Shop $shop, Customer $customer, array $data): array
    {
        if (! empty($data['address_id'])) {
            $address = Address::query()
                ->where('customer_id', $customer->id)
                ->where('shop_id', $shop->id)
                ->find($data['address_id']);

            if (! $address) {
                throw new RuntimeException('That address could not be found.');
            }

            return $address->only([
                'recipient_name', 'mobile', 'address_line1', 'address_line2',
                'village', 'taluka', 'district', 'city', 'state', 'pincode',
            ]);
        }

        if (! empty($data['address'])) {
            return $data['address'];
        }

        throw new RuntimeException('Please choose or add a delivery address.');
    }
}
