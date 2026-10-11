<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\GoodsReceipt;
use App\Models\KitchenLine;
use App\Models\Sale;
use App\Models\SalesItem;
use App\Models\StockItem;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierPayment;
use App\Support\Outlets;
use App\Support\Units;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ERP's reports. Each method answers one question an owner asks, from the
 * ledgers as they stand - nothing here is stored, so nothing can go stale.
 *
 * Sales, tax on sales and day-close reports are CRM's; these are the ones
 * that need to know what things cost.
 */
class Reports
{
    /**
     * Food cost: what was sold, what the food in it cost, and the
     * percentage between the two - overall and dish by dish.
     *
     * A dish's cost is what its bill line took out of stock itself plus
     * what the kitchen cooked for it. Food cooked and then cancelled is
     * shown apart: it cost money and earned none.
     *
     * @return array{revenue: int, cost: int, wasted: int, percent: ?string, dishes: Collection, uncosted: int}
     */
    public function foodCost(string $outletId, string $from, string $to): array
    {
        $sold = DB::table('sale_lines')
            ->join('sales', 'sales.id', '=', 'sale_lines.sale_id')
            ->where('sales.outlet_id', $outletId)
            ->where('sales.status', Sale::RECORDED)
            ->whereBetween('sales.business_date', [$from, $to.' 23:59:59'])
            ->groupBy('sale_lines.sales_item_id', 'sale_lines.name')
            ->selectRaw('sale_lines.sales_item_id, sale_lines.name, SUM(sale_lines.qty) as qty, SUM(sale_lines.net_paise) as net, SUM(sale_lines.cost_paise) as cost')
            ->get();

        $cooked = KitchenLine::allOutlets()
            ->where('outlet_id', $outletId)
            ->whereNotNull('consumed_at')
            ->whereBetween('business_date', [$from, $to.' 23:59:59'])
            ->groupBy('sales_item_id', 'wasted')
            ->selectRaw('sales_item_id, wasted, SUM(cost_paise) as cost')
            ->get();

        $kitchenCost = $cooked->where('wasted', false)->pluck('cost', 'sales_item_id');
        $names = SalesItem::allOutlets()->where('outlet_id', $outletId)->pluck('name', 'id');

        $dishes = $sold->groupBy(fn ($row) => $row->sales_item_id ?: 'x:'.$row->name)->map(function ($rows, $key) use ($kitchenCost, $names) {
            $itemId = (int) $rows->first()->sales_item_id;
            $net = (int) $rows->sum('net');
            $cost = (int) $rows->sum('cost') + (int) ($itemId ? ($kitchenCost[$itemId] ?? 0) : 0);

            return [
                'name' => $itemId ? ($names[$itemId] ?? $rows->first()->name) : $rows->first()->name,
                'qty' => (int) $rows->sum('qty'),
                'net' => $net,
                'cost' => $cost,
                'percent' => Availability::percent($cost > 0 ? $cost : null, $net),
            ];
        })->sortByDesc('net')->values();

        $revenue = (int) $dishes->sum('net');
        $cost = (int) $dishes->sum('cost');

        return [
            'revenue' => $revenue,
            'cost' => $cost,
            'wasted' => (int) $cooked->where('wasted', true)->sum('cost'),
            'percent' => Availability::percent($cost > 0 ? $cost : null, $revenue),
            'dishes' => $dishes,
            // Dishes sold with no recipe: their cost is not in the figure.
            'uncosted' => $dishes->where('cost', 0)->count(),
        ];
    }

    /**
     * Variance: for each stock item, what the recipes say was used against
     * what the counts say is gone.
     *
     * The difference a count finds is exactly the stock that left without
     * a recipe, a wastage note or a transfer explaining it - so the count
     * adjustments are the variance, and the rest of the row is the context
     * it is read in.
     *
     * Stock from another outlet counts as having come in, and stock sent
     * to one is its own column. A move between two stores of this outlet is
     * neither: it left one shelf for another and the outlet has what it had.
     *
     * @return Collection<int, array{item: StockItem, received: int, used: int, wasted: int, sent: int, difference: int, difference_value: int}>
     */
    public function variance(string $outletId, string $from, string $to): Collection
    {
        $sums = StockMovement::allOutlets()
            ->where('outlet_id', $outletId)
            ->whereBetween('business_date', [$from, $to.' 23:59:59'])
            ->groupBy('stock_item_id', 'type')
            ->selectRaw('stock_item_id, type, SUM(qty) as qty, SUM(value_paise) as value')
            ->get()
            ->groupBy('stock_item_id');

        $items = StockItem::allTenants()->whereIn('id', $sums->keys())->get()->keyBy('id');

        return $sums->map(function ($rows, $itemId) use ($items) {
            $qty = fn (string ...$types) => (int) $rows->whereIn('type', $types)->sum('qty');

            return [
                'item' => $items[$itemId] ?? null,
                'received' => $qty(StockMovement::RECEIPT, StockMovement::OPENING, StockMovement::PRODUCTION_IN, StockMovement::CUSTOMER_RETURN, StockMovement::SUPPLIER_RETURN, StockMovement::OUTLET_IN),
                'used' => -$qty(StockMovement::CONSUMPTION, StockMovement::REVERSAL, StockMovement::PRODUCTION_OUT),
                'wasted' => -$qty(StockMovement::WASTAGE),
                'sent' => -$qty(StockMovement::OUTLET_OUT),
                'difference' => $qty(StockMovement::ADJUSTMENT),
                'difference_value' => (int) $rows->where('type', StockMovement::ADJUSTMENT)->sum('value'),
            ];
        })->filter(fn (array $row) => $row['item'] !== null)
            ->sortBy(fn (array $row) => $row['difference_value'])
            ->values();
    }

    /**
     * Stock value: what is on the shelf and what it is worth, by category.
     *
     * `in_transit` is what this outlet has sent to another that has not
     * been received yet: off the shelf, so not in the total, but not the
     * other outlet's either.
     *
     * @return array{total: int, in_transit: int, categories: Collection}
     */
    public function stockValue(string $outletId): array
    {
        $levels = StockLevel::allOutlets()->where('outlet_id', $outletId)->where('qty', '!=', 0)->get(['stock_item_id', 'qty', 'cost_rate'])
            ->groupBy('stock_item_id');

        $items = StockItem::allTenants()->whereIn('id', $levels->keys())->orderBy('name')->get();

        $rows = $items->map(fn (StockItem $item) => [
            'item' => $item,
            'qty' => (int) $levels[$item->id]->sum('qty'),
            'value' => (int) $levels[$item->id]->sum(fn (StockLevel $level) => Units::value($level->qty, $level->cost_rate)),
        ]);

        return [
            'total' => (int) $rows->sum('value'),
            'in_transit' => app(Transfers::class)->onTheWayFrom($outletId),
            'categories' => $rows->groupBy(fn (array $row) => $row['item']->category ?? 'Uncategorised')
                ->map(fn (Collection $group, string $name) => ['name' => $name, 'value' => (int) $group->sum('value'), 'items' => $group->sortByDesc('value')->values()])
                ->sortByDesc('value')->values(),
        ];
    }

    /**
     * Expiry: every batch with stock in it that has gone off or will within
     * thirty days, soonest first.
     *
     * @return Collection<int, array{batch: Batch, qty: int, value: int, days: int}>
     */
    public function expiry(string $outletId): Collection
    {
        $today = Outlets::businessDate($outletId);
        $horizon = now()->parse($today)->addDays(30)->toDateString();

        $held = StockLevel::allOutlets()->where('outlet_id', $outletId)->where('batch_id', '>', 0)->where('qty', '>', 0)
            ->get(['batch_id', 'qty', 'cost_rate'])->groupBy('batch_id');

        return Batch::allOutlets()->with('item')
            ->whereIn('id', $held->keys())
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<=', $horizon.' 23:59:59')
            ->orderBy('expiry_date')
            ->get()
            ->map(fn (Batch $batch) => [
                'batch' => $batch,
                'qty' => (int) $held[$batch->id]->sum('qty'),
                'value' => (int) $held[$batch->id]->sum(fn (StockLevel $level) => Units::value($level->qty, $level->cost_rate)),
                'days' => (int) now()->parse($today)->diffInDays($batch->expiry_date, false),
            ]);
    }

    /**
     * Purchases: what was bought in a period, by supplier and by item.
     *
     * @return array{total: int, tax: int, suppliers: Collection, items: Collection}
     */
    public function purchases(string $outletId, string $from, string $to): array
    {
        $receipts = GoodsReceipt::allOutlets()->with('supplier')
            ->where('outlet_id', $outletId)
            ->whereBetween('received_on', [$from, $to.' 23:59:59'])
            ->get();

        $lines = DB::table('goods_receipt_lines')
            ->whereIn('goods_receipt_id', $receipts->pluck('id'))
            ->groupBy('stock_item_id')
            ->selectRaw('stock_item_id, SUM(qty) as qty, SUM(net_paise) as net, SUM(tax_paise) as tax')
            ->get();

        $items = StockItem::allTenants()->whereIn('id', $lines->pluck('stock_item_id'))->get()->keyBy('id');

        return [
            'total' => (int) $receipts->sum('total_paise'),
            'tax' => (int) $receipts->sum('tax_paise'),
            'suppliers' => $receipts->groupBy(fn (GoodsReceipt $r) => $r->supplier?->name ?? 'No supplier named')
                ->map(fn (Collection $group, string $name) => [
                    'name' => $name,
                    'deliveries' => $group->count(),
                    'net' => (int) $group->sum('net_paise'),
                    'tax' => (int) $group->sum('tax_paise'),
                    'total' => (int) $group->sum('total_paise'),
                ])->sortByDesc('total')->values(),
            'items' => $lines->map(fn ($row) => [
                'item' => $items[$row->stock_item_id] ?? null,
                'qty' => (int) $row->qty,
                'net' => (int) $row->net,
                'tax' => (int) $row->tax,
            ])->filter(fn (array $row) => $row['item'] !== null)->sortByDesc('net')->values(),
        ];
    }

    /**
     * The purchase register: every line of every delivery in a period, one
     * row each. Added up by supplier or by item it is the two tables of
     * purchases() - which is why it, and not they, is what is downloaded.
     *
     * @return Collection<int, object{received_on: string, number: string, supplier: ?string, bill_no: ?string, item: string, base_unit: string, qty: int, net_paise: int, tax_paise: int}>
     */
    public function purchaseRegister(string $outletId, string $from, string $to): Collection
    {
        return DB::table('goods_receipt_lines')
            ->join('goods_receipts', 'goods_receipts.id', '=', 'goods_receipt_lines.goods_receipt_id')
            ->join('stock_items', 'stock_items.id', '=', 'goods_receipt_lines.stock_item_id')
            ->leftJoin('suppliers', 'suppliers.id', '=', 'goods_receipts.supplier_id')
            ->where('goods_receipts.outlet_id', $outletId)
            ->whereBetween('goods_receipts.received_on', [$from, $to.' 23:59:59'])
            ->orderBy('goods_receipts.received_on')->orderBy('goods_receipts.id')->orderBy('goods_receipt_lines.id')
            ->get([
                'goods_receipts.received_on', 'goods_receipts.number', 'suppliers.name as supplier', 'goods_receipts.bill_no',
                'stock_items.name as item', 'stock_items.base_unit', 'goods_receipt_lines.qty',
                'goods_receipt_lines.net_paise', 'goods_receipt_lines.tax_paise',
            ]);
    }

    /**
     * Payables: what is owed to each supplier and how late it is.
     *
     * @return array{total: int, overdue: int, suppliers: Collection}
     */
    public function payables(string $outletId): array
    {
        $today = Outlets::businessDate($outletId);
        $lateFrom = now()->parse($today)->subDays(30)->toDateString();

        $bills = SupplierBill::allOutlets()->where('outlet_id', $outletId)->where('status', SupplierBill::OPEN)->get()->groupBy('supplier_id');

        $advances = SupplierPayment::allOutlets()->where('outlet_id', $outletId)->whereNull('supplier_bill_id')
            ->groupBy('supplier_id')->selectRaw('supplier_id, SUM(amount_paise) as total')->pluck('total', 'supplier_id');

        $suppliers = Supplier::allTenants()->whereIn('id', $bills->keys()->merge($advances->keys())->unique())->orderBy('name')->get();

        $rows = $suppliers->map(function (Supplier $supplier) use ($bills, $advances, $today, $lateFrom) {
            $open = $bills[$supplier->id] ?? collect();
            $due = fn (Collection $set) => (int) $set->sum(fn (SupplierBill $bill) => $bill->duePaise());
            $date = fn (SupplierBill $bill) => $bill->due_on?->toDateString();

            return [
                'supplier' => $supplier,
                'not_due' => $due($open->filter(fn ($b) => $date($b) === null || $date($b) >= $today)),
                'late' => $due($open->filter(fn ($b) => $date($b) !== null && $date($b) < $today && $date($b) >= $lateFrom)),
                'very_late' => $due($open->filter(fn ($b) => $date($b) !== null && $date($b) < $lateFrom)),
                'advance' => (int) ($advances[$supplier->id] ?? 0),
                'owed' => $due($open) - (int) ($advances[$supplier->id] ?? 0),
            ];
        })->filter(fn (array $row) => $row['owed'] !== 0 || $row['advance'] !== 0)->sortByDesc('owed')->values();

        return [
            'total' => (int) $rows->sum('owed'),
            'overdue' => (int) $rows->sum('late') + (int) $rows->sum('very_late'),
            'suppliers' => $rows,
        ];
    }
}
