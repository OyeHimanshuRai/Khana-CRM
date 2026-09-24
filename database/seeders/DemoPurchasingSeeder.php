<?php

namespace Database\Seeders;

use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\PurchaseReturnService;
use App\Services\PurchaseService;
use App\Support\CurrentShop;
use Database\Seeders\Concerns\SeedsDemoData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Everything that put stock on the shelf.
 *
 * Receipts are posted through PurchaseService rather than written straight
 * to the table, which is the whole reason this seeder is worth having: the
 * service is what creates the batches, values them at landed cost, bills the
 * supplier's ledger and ticks the quantities off the purchase order. Insert
 * the rows directly and the stock report, the supplier statement and the
 * batch screen would each disagree with the other two.
 *
 * A handful of lots are given expiry dates in the past and in the next few
 * weeks on purpose, so the dashboard's expired and near-expiry alerts have
 * something true to say.
 */
class DemoPurchasingSeeder extends Seeder
{
    use SeedsDemoData;

    public function __construct(
        private readonly PurchaseService $purchases,
        private readonly PurchaseReturnService $returns,
    ) {}

    public function run(): void
    {
        $this->seedRandom(3);

        $this->purchaseOrders();
        $this->goodsReceipts();
        $this->purchaseReturns();
    }

    /**
     * The rows a restaurant can actually order from a supplier.
     *
     * Ingredients and bought-in goods - flour, paneer, oil, bottled drinks -
     * and never a dish. A kitchen does not buy Chilli Chicken from anybody; it
     * buys what goes into one and cooks the rest. Every made-to-order row is
     * also a row with no stock of its own (see Product::tracksStock()), so
     * receiving one wrote a shelf quantity against a dish that no sale would
     * ever move - a count guaranteed to be wrong from the first service.
     *
     * @return \Illuminate\Support\Collection<int, Product>
     */
    private function buyable()
    {
        $buyable = Product::query()
            ->where('is_made_to_order', false)
            ->with(['unit', 'taxRate'])
            ->get();

        // A catalogue of nothing but dishes is not one this seeder can serve
        // sensibly, and silently buying dishes again would be worse than
        // saying so.
        return $buyable->isNotEmpty()
            ? $buyable
            : Product::query()->with(['unit', 'taxRate'])->get();
    }

    /**
     * Twenty orders spread across the lifecycle.
     *
     * Not all of them are received: an orders list where nothing is ever
     * still pending approval is not a list anybody needs to open.
     */
    private function purchaseOrders(): void
    {
        if ($this->alreadySeeded('Purchase orders', PurchaseOrder::query()->count())) {
            return;
        }

        $shop = CurrentShop::get();
        $warehouse = Warehouse::defaultFor($shop->id);
        $suppliers = Supplier::query()->where('is_active', true)->get();
        $products = $this->buyable();

        $statuses = [
            PurchaseOrder::DRAFT, PurchaseOrder::DRAFT,
            PurchaseOrder::PENDING, PurchaseOrder::PENDING, PurchaseOrder::PENDING,
            PurchaseOrder::APPROVED, PurchaseOrder::APPROVED, PurchaseOrder::APPROVED,
            PurchaseOrder::APPROVED, PurchaseOrder::APPROVED,
            PurchaseOrder::PARTIAL, PurchaseOrder::PARTIAL,
            PurchaseOrder::RECEIVED, PurchaseOrder::RECEIVED, PurchaseOrder::RECEIVED,
            PurchaseOrder::RECEIVED, PurchaseOrder::RECEIVED, PurchaseOrder::RECEIVED,
            PurchaseOrder::CANCELLED, PurchaseOrder::CANCELLED,
        ];

        $user = auth()->user();

        for ($i = 0; $i < self::PER_MODULE; $i++) {
            $orderedOn = Carbon::today()->subDays($this->between(5, 120));
            $status = $statuses[$i];

            $order = PurchaseOrder::query()->create([
                'shop_id' => $shop->id,
                'supplier_id' => $suppliers->random()->id,
                'warehouse_id' => $warehouse?->id,
                'reference' => PurchaseOrder::nextReference($shop),
                'ordered_on' => $orderedOn,
                'expected_on' => $orderedOn->copy()->addDays($this->between(3, 21)),
                'status' => $status,
                'notes' => $this->pick([
                    'Top-up before the weekend rush.',
                    'Weekly standing order for the kitchen.',
                    'Against the supplier scheme discount.',
                    null,
                ]),
                'created_by' => $user?->id,
                'created_by_name' => $user?->name ?? 'System',
            ]);

            if (in_array($status, [PurchaseOrder::APPROVED, PurchaseOrder::PARTIAL, PurchaseOrder::RECEIVED], true)) {
                $order->forceFill([
                    'approved_by' => $user?->id,
                    'approved_by_name' => $user?->name ?? 'System',
                    'approved_at' => $orderedOn->copy()->addDay(),
                ])->save();
            }

            $subtotal = 0.0;
            $taxTotal = 0.0;

            foreach ($products->random($this->between(2, 5)) as $product) {
                $quantity = $this->between(10, 120);
                $cost = round((float) $product->purchase_price * $this->money(0.94, 1.04), 2);
                $rate = (float) ($product->taxRate?->rate ?? 0);
                $lineTotal = round($quantity * $cost, 2);

                PurchaseOrderItem::query()->create([
                    'purchase_order_id' => $order->id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'sku' => $product->sku,
                    'unit_code' => $product->unit?->code,
                    'quantity' => $quantity,
                    'received_quantity' => $status === PurchaseOrder::RECEIVED
                        ? $quantity
                        : ($status === PurchaseOrder::PARTIAL ? floor($quantity / 2) : 0),
                    'unit_cost' => $cost,
                    'tax_rate' => $rate,
                    'line_total' => $lineTotal,
                ]);

                $subtotal += $lineTotal;
                $taxTotal += round($lineTotal * $rate / 100, 2);
            }

            $order->forceFill([
                'subtotal' => round($subtotal, 2),
                'tax_total' => round($taxTotal, 2),
                'grand_total' => round($subtotal + $taxTotal, 2),
            ])->save();
        }

        $this->say(sprintf('%d purchase orders.', self::PER_MODULE));
    }

    /**
     * Twenty receipts, eighteen of them posted.
     *
     * Posting is what actually creates stock, so every product is covered by
     * at least one line - a catalogue where half the items have never been
     * received cannot be sold from, and DemoSalesSeeder runs next.
     */
    private function goodsReceipts(): void
    {
        if ($this->alreadySeeded('Goods receipts', GoodsReceipt::query()->count())) {
            return;
        }

        $shop = CurrentShop::get();
        $warehouses = Warehouse::allShops()->where('shop_id', $shop->id)->get();
        $main = $warehouses->firstWhere('is_default', true) ?? $warehouses->first();
        $suppliers = Supplier::query()->where('is_active', true)->get();
        $products = $this->buyable()->sortBy('id')->values();

        $user = auth()->user();
        $posted = 0;

        /*
         | Every product appears in the first four receipts, so the shelf is
         | stocked before anything is sold. The rest are top-ups on random
         | lines, which is what gives the stock ledger more than one movement
         | per product to show.
         */
        $coverage = $products->chunk((int) ceil($products->count() / 4));

        for ($i = 0; $i < self::PER_MODULE; $i++) {
            $supplier = $suppliers->random();
            $receivedOn = $i < 4
                ? Carbon::today()->subDays(120 - $i * 5)
                : Carbon::today()->subDays($this->between(2, 100));

            $lines = $coverage[$i] ?? $products->random($this->between(2, 5));

            $receipt = GoodsReceipt::query()->create([
                'shop_id' => $shop->id,
                'supplier_id' => $supplier->id,
                'warehouse_id' => ($i % 5 === 4 ? $warehouses->random() : $main)->id,
                'reference' => GoodsReceipt::nextReference($shop),
                'received_on' => $receivedOn,
                'status' => GoodsReceipt::DRAFT,
                'bill_number' => 'BILL/'.$receivedOn->format('y').'/'.str_pad((string) ($i + 101), 4, '0', STR_PAD_LEFT),
                'bill_date' => $receivedOn,
                // Freight the shop actually paid, spread across the lines as
                // landed cost by the service.
                'other_charges' => $this->chance(60) ? $this->money(250, 2400) : 0,
                'is_inter_state' => strcasecmp((string) $supplier->state, (string) $shop->state) !== 0,
                'notes' => $this->pick(['Received in full.', 'Two bags torn, noted with the driver.', null]),
                'created_by' => $user?->id,
                'created_by_name' => $user?->name ?? 'System',
            ]);

            foreach ($lines as $index => $product) {
                $this->receiptLine($receipt, $product, $receivedOn, $i, $index);
            }

            // Two left in draft, so the "post this receipt" screen has work
            // waiting on it.
            if ($i >= self::PER_MODULE - 2) {
                continue;
            }

            $paid = $this->chance(55) ? null : 0.0;

            $this->purchases->post($receipt);

            if ($paid === null) {
                // Settled in full on delivery, recorded after posting so the
                // ledger shows a bill and then a payment against it.
                $receipt->forceFill(['paid_total' => $receipt->grand_total, 'due_total' => 0])->save();
            }

            $posted++;
        }

        $this->say(sprintf('%d goods receipts (%d posted).', self::PER_MODULE, $posted));
    }

    /**
     * One receipt line, with a lot number when the product needs one.
     *
     * The expiry spread is deliberate: most lots are comfortably in date, one
     * in twelve is already past it, and one in eight falls due inside the
     * ninety days Batch::NEAR_EXPIRY_DAYS calls "near".
     */
    private function receiptLine(
        GoodsReceipt $receipt,
        Product $product,
        Carbon $receivedOn,
        int $receiptIndex,
        int $lineIndex,
    ): void {
        $quantity = $this->between(20, 150);
        $cost = round((float) $product->purchase_price * $this->money(0.93, 1.05), 2);

        $expiry = null;
        $mfg = null;
        $batchNo = null;

        if ($product->track_batches) {
            $mfg = $receivedOn->copy()->subMonths($this->between(1, 8));

            $expiry = match (true) {
                $this->chance(8) => Carbon::today()->subDays($this->between(5, 60)),
                $this->chance(14) => Carbon::today()->addDays($this->between(10, 80)),
                default => Carbon::today()->addMonths($this->between(8, 30)),
            };

            $batchNo = strtoupper(substr($product->sku, 0, 4)).'-'
                .$receivedOn->format('ym').'-'
                .str_pad((string) ($receiptIndex * 10 + $lineIndex + 1), 3, '0', STR_PAD_LEFT);
        }

        GoodsReceiptItem::query()->create([
            'goods_receipt_id' => $receipt->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'unit_code' => $product->unit?->code,
            'batch_no' => $batchNo,
            'mfg_date' => $mfg,
            'expiry_date' => $expiry,
            'quantity' => $quantity,
            // Trade schemes are usually "buy ten get one", and free stock
            // still has to arrive on the shelf.
            'free_quantity' => $this->chance(20) ? max(1, (int) floor($quantity / 10)) : 0,
            'unit_cost' => $cost,
            'discount_percent' => $this->chance(35) ? $this->money(1, 8) : 0,
            'tax_rate' => (float) ($product->taxRate?->rate ?? 0),
            'mrp' => $product->mrp,
            'selling_price' => $product->selling_price,
        ]);
    }

    /**
     * A few consignments sent back.
     *
     * Fewer than twenty on purpose - a shop that returns a fifth of what it
     * buys has a supplier problem, not a demo. Approved through the service
     * so the stock leaves and the supplier is credited.
     */
    private function purchaseReturns(): void
    {
        if ($this->alreadySeeded('Purchase returns', PurchaseReturn::query()->count(), 6)) {
            return;
        }

        $shop = CurrentShop::get();
        $user = auth()->user();

        $receipts = GoodsReceipt::query()
            ->where('status', GoodsReceipt::POSTED)
            ->with(['items.product.unit', 'supplier'])
            ->latest('received_on')
            ->take(6)
            ->get();

        $made = 0;

        foreach ($receipts as $index => $receipt) {
            $item = $receipt->items->first();

            if (! $item || ! $item->product) {
                continue;
            }

            $quantity = max(1, (int) floor((float) $item->quantity * 0.1));

            $return = PurchaseReturn::query()->create([
                'shop_id' => $shop->id,
                'warehouse_id' => $receipt->warehouse_id,
                'goods_receipt_id' => $receipt->id,
                'supplier_id' => $receipt->supplier_id,
                'supplier_name' => $receipt->supplier?->name,
                'reference' => PurchaseReturn::nextReference($shop),
                'returned_on' => $receipt->received_on->copy()->addDays($this->between(1, 10)),
                'status' => PurchaseReturn::DRAFT,
                'reason_code' => $this->pickKey(PurchaseReturn::REASONS),
                'reason' => 'Raised against bill '.$receipt->bill_number.'.',
                'settlement' => $this->pick([PurchaseReturn::CREDIT, PurchaseReturn::REFUND]),
                'created_by' => $user?->id,
                'created_by_name' => $user?->name ?? 'System',
            ]);

            $taxable = round($quantity * (float) $item->unit_cost, 2);
            $rate = (float) $item->tax_rate;
            $tax = round($taxable * $rate / 100, 2);

            PurchaseReturnItem::query()->create([
                'purchase_return_id' => $return->id,
                'goods_receipt_item_id' => $item->id,
                'product_id' => $item->product_id,
                'batch_id' => $item->batch_id,
                'product_name' => $item->product_name,
                'sku' => $item->sku,
                'unit_code' => $item->unit_code,
                'batch_no' => $item->batch_no,
                'quantity' => $quantity,
                'unit_cost' => $item->unit_cost,
                'taxable_value' => $taxable,
                'tax_rate' => $rate,
                'tax_amount' => $tax,
                'line_total' => round($taxable + $tax, 2),
            ]);

            $return->forceFill([
                'subtotal' => $taxable,
                'tax_total' => $tax,
                'grand_total' => round($taxable + $tax, 2),
            ])->save();

            // Two are left awaiting a decision, so the approvals queue is not
            // empty on a screen whose whole purpose is approving things.
            if ($index < 4) {
                $this->returns->approve($return, 'Credit note received from the supplier.');
                $made++;
            }
        }

        $this->say(sprintf('%d purchase returns (%d approved).', $receipts->count(), $made));
    }
}
