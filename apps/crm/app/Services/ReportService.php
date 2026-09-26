<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\CashRegister;
use App\Models\CouponRedemption;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Support\CurrentShop;
use App\Support\ReportFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Every report the SRS asks for, computed in one place.
 *
 * Reports read across several modules at once, so putting the queries here
 * rather than in controllers keeps two things true: the definitions are in
 * one file where they can be compared, and every report agrees about what a
 * sale is. That last point matters more than it sounds - a sales report and
 * a profit report that disagree by the value of the cancelled invoices are
 * worse than no reports at all.
 *
 * The rules, applied everywhere:
 *
 *   - Draft and cancelled invoices are never counted as sales.
 *   - Only cleared payments count as collected; a pending cheque has not
 *     paid anything.
 *   - Cost is what was captured at the moment of sale, not today's average.
 *   - Every figure is shop-scoped through the models' own global scope.
 */
class ReportService
{
    /* -------------------------------------------------------------- sales */

    /**
     * Sales by day, with the invoice count and the tax collected.
     *
     * @return Collection<int, object>
     */
    public function salesByDay(ReportFilters $range): Collection
    {
        return $this->salesBase($range)
            ->selectRaw('DATE(invoiced_at) as period')
            ->selectRaw('COUNT(*) as invoices')
            ->selectRaw('COALESCE(SUM(subtotal), 0) as taxable')
            ->selectRaw('COALESCE(SUM(tax_total), 0) as tax')
            ->selectRaw('COALESCE(SUM(grand_total), 0) as total')
            ->selectRaw('COALESCE(SUM(paid_total), 0) as collected')
            ->selectRaw('COALESCE(SUM(cost_total), 0) as cost')
            ->groupBy('period')
            ->orderBy('period')
            ->get();
    }

    /**
     * The headline numbers, with the previous period alongside.
     *
     * @return array<string, mixed>
     */
    public function salesSummary(ReportFilters $range): array
    {
        $current = $this->salesTotals($range->from, $range->to);

        [$previousFrom, $previousTo] = $range->previousPeriod();
        $previous = $this->salesTotals($previousFrom, $previousTo);

        $change = $previous['total'] > 0
            ? (($current['total'] - $previous['total']) / $previous['total']) * 100
            : null;

        return [
            'current' => $current,
            'previous' => $previous,
            'change' => $change,
            'average_bill' => $current['invoices'] > 0
                ? $current['total'] / $current['invoices']
                : 0.0,
        ];
    }

    /**
     * @return array<string, float|int>
     */
    private function salesTotals($from, $to): array
    {
        $row = Invoice::query()
            ->counted()
            ->whereBetween('invoiced_at', [$from, $to])
            ->selectRaw('COUNT(*) as invoices')
            ->selectRaw('COALESCE(SUM(subtotal), 0) as taxable')
            ->selectRaw('COALESCE(SUM(tax_total), 0) as tax')
            ->selectRaw('COALESCE(SUM(grand_total), 0) as total')
            ->selectRaw('COALESCE(SUM(paid_total), 0) as collected')
            ->selectRaw('COALESCE(SUM(due_total), 0) as outstanding')
            ->selectRaw('COALESCE(SUM(cost_total), 0) as cost')
            ->first();

        return [
            'invoices' => (int) $row->invoices,
            'taxable' => (float) $row->taxable,
            'tax' => (float) $row->tax,
            'total' => (float) $row->total,
            'collected' => (float) $row->collected,
            'outstanding' => (float) $row->outstanding,
            'cost' => (float) $row->cost,
            'profit' => (float) $row->taxable - (float) $row->cost,
        ];
    }

    /* ------------------------------------------------------------ product */

    /**
     * Sales by product, with margin.
     *
     * @return Collection<int, object>
     */
    public function salesByProduct(ReportFilters $range, int $limit = 200): Collection
    {
        return $this->lineBase($range)
            ->selectRaw('invoice_items.product_id, invoice_items.product_name, invoice_items.sku')
            ->selectRaw('SUM(invoice_items.quantity) as quantity')
            ->selectRaw('SUM(invoice_items.taxable_value) as revenue')
            ->selectRaw('SUM(invoice_items.quantity * invoice_items.unit_cost) as cost')
            ->selectRaw('SUM(invoice_items.line_total) as billed')
            ->groupBy('invoice_items.product_id', 'invoice_items.product_name', 'invoice_items.sku')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get();
    }

    /**
     * Sales by category.
     *
     * Joined through products rather than copied onto the line, because a
     * product moving category should move its history with it - unlike its
     * name and price, which the invoice must keep as billed.
     *
     * @return Collection<int, object>
     */
    public function salesByCategory(ReportFilters $range): Collection
    {
        return $this->lineBase($range)
            ->join('products', 'products.id', '=', 'invoice_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->selectRaw("COALESCE(categories.name, 'Uncategorised') as category")
            ->selectRaw('SUM(invoice_items.quantity) as quantity')
            ->selectRaw('SUM(invoice_items.taxable_value) as revenue')
            ->selectRaw('SUM(invoice_items.quantity * invoice_items.unit_cost) as cost')
            ->groupBy('category')
            ->orderByDesc('revenue')
            ->get();
    }

    /**
     * Sales by whoever rang them up.
     *
     * @return Collection<int, object>
     */
    public function salesByEmployee(ReportFilters $range): Collection
    {
        return $this->salesBase($range)
            ->selectRaw("COALESCE(created_by_name, 'Unattributed') as person")
            ->selectRaw('COUNT(*) as invoices')
            ->selectRaw('COALESCE(SUM(grand_total), 0) as total')
            ->selectRaw('COALESCE(SUM(subtotal) - SUM(cost_total), 0) as profit')
            ->groupBy('person')
            ->orderByDesc('total')
            ->get();
    }

    /**
     * Sales by shop - only meaningful in the consolidated view.
     *
     * @return Collection<int, object>
     */
    public function salesByShop(ReportFilters $range): Collection
    {
        return $this->salesBase($range)
            ->join('shops', 'shops.id', '=', 'invoices.shop_id')
            ->selectRaw('shops.name as shop')
            ->selectRaw('COUNT(*) as invoices')
            ->selectRaw('COALESCE(SUM(invoices.grand_total), 0) as total')
            ->selectRaw('COALESCE(SUM(invoices.subtotal) - SUM(invoices.cost_total), 0) as profit')
            ->groupBy('shops.name')
            ->orderByDesc('total')
            ->get();
    }

    /**
     * Sales by table (§13).
     *
     * Dine-in only, and it says so rather than quietly lumping counter sales
     * into a row called "no table". A takeaway has no table to be busy, and
     * including it would make the busiest "table" in the restaurant the till.
     *
     * Joined through the sitting rather than through the order, because a
     * table's takings are what was *billed* at it - several rounds on one
     * bill, which is exactly what §3.11 arranges.
     *
     * @return Collection<int, object>
     */
    public function salesByTable(ReportFilters $range): Collection
    {
        return $this->salesBase($range)
            ->join('table_sessions', 'table_sessions.id', '=', 'invoices.table_session_id')
            ->join('restaurant_tables', 'restaurant_tables.id', '=', 'table_sessions.restaurant_table_id')
            ->leftJoin('floors', 'floors.id', '=', 'restaurant_tables.floor_id')
            ->selectRaw("COALESCE(floors.name, '-') as area")
            ->selectRaw('restaurant_tables.name as table_name')
            ->selectRaw('restaurant_tables.capacity as seats')
            ->selectRaw('COUNT(*) as bills')
            ->selectRaw('COALESCE(SUM(table_sessions.covers), 0) as covers')
            ->selectRaw('COALESCE(SUM(invoices.grand_total), 0) as total')
            ->selectRaw('COALESCE(AVG(invoices.grand_total), 0) as average')
            ->groupBy('area', 'table_name', 'seats')
            ->orderByDesc('total')
            ->get();
    }

    /**
     * Discounts given, and coupons redeemed (§13).
     *
     * Two things on one report because they are the same question asked
     * twice: how much did we not charge, and who decided that.
     *
     * Line discounts and bill discounts are kept apart, as the invoice keeps
     * them apart. A shop that discounts every line has a pricing problem; a
     * shop that discounts whole bills has a manager problem, and a single
     * total would hide which.
     *
     * @return Collection<int, object>
     */
    public function discountsByDay(ReportFilters $range): Collection
    {
        return $this->salesBase($range)
            ->selectRaw('DATE(invoiced_at) as day')
            ->selectRaw('COUNT(*) as bills')
            ->selectRaw('COALESCE(SUM(line_discount_total), 0) as line_discount')
            ->selectRaw('COALESCE(SUM(invoice_discount), 0) as bill_discount')
            ->selectRaw('COALESCE(SUM(line_discount_total) + SUM(invoice_discount), 0) as total_discount')
            ->selectRaw('COALESCE(SUM(grand_total), 0) as billed')
            ->groupBy('day')
            /*
             | Only days where something was actually given away. A discount
             | report padded with rows of zeroes is a report nobody scrolls to
             | the bottom of, and the days that matter are the exceptions.
             */
            ->havingRaw('SUM(line_discount_total) + SUM(invoice_discount) > 0')
            ->orderByDesc('day')
            ->get();
    }

    /**
     * Which coupons were actually used.
     *
     * @return Collection<int, object>
     */
    public function couponsRedeemed(ReportFilters $range): Collection
    {
        return CouponRedemption::query()
            // CouponRedemption carries no scope trait on purpose - it is
            // written under the storefront `customer` guard, where a global
            // scope is a no-op - so the branch has to be named here, as
            // lineBase() and kitchenPerformance() do.
            ->whereIn('coupon_redemptions.shop_id', $this->shopIds())
            ->whereBetween('coupon_redemptions.created_at', [$range->from, $range->to])
            ->join('coupons', 'coupons.id', '=', 'coupon_redemptions.coupon_id')
            ->selectRaw('coupons.code as code')
            ->selectRaw('coupons.description as name')
            ->selectRaw('COUNT(*) as uses')
            ->selectRaw('COALESCE(SUM(coupon_redemptions.discount_amount), 0) as given')
            ->groupBy('coupons.code', 'coupons.description')
            ->orderByDesc('given')
            ->get();
    }

    /**
     * Orders that were cancelled (§13).
     *
     * Rows rather than a total, because the only useful version of this
     * report is the one somebody reads line by line: a pattern in the reasons
     * or in who cancelled is the entire point, and a count of 14 says nothing
     * anybody can act on.
     *
     * @return Collection<int, Order>
     */
    public function cancelledOrders(ReportFilters $range, int $limit = 500): Collection
    {
        return Order::query()
            ->where('status', Order::CANCELLED)
            ->whereBetween('cancelled_at', [$range->from, $range->to])
            ->with(['cancelledBy:id,name', 'customer:id,name'])
            ->orderByDesc('cancelled_at')
            ->limit($limit)
            ->get();
    }

    /**
     * What each customer has spent (§13).
     *
     * Walk-ins are excluded rather than grouped into one enormous row. A
     * customer report is for the people the restaurant can recognise again;
     * "Walk-in: 4,812 visits" is a number nobody can do anything with.
     *
     * @return Collection<int, object>
     */
    public function customerHistory(ReportFilters $range, int $limit = 500): Collection
    {
        return $this->salesBase($range)
            ->whereNotNull('invoices.customer_id')
            ->join('customers', 'customers.id', '=', 'invoices.customer_id')
            ->selectRaw('customers.id as customer_id')
            ->selectRaw('customers.name as customer')
            ->selectRaw('customers.mobile as mobile')
            ->selectRaw('COUNT(*) as visits')
            ->selectRaw('COALESCE(SUM(invoices.grand_total), 0) as total')
            ->selectRaw('COALESCE(AVG(invoices.grand_total), 0) as average')
            ->selectRaw('MAX(invoices.invoiced_at) as last_visit')
            ->groupBy('customers.id', 'customers.name', 'customers.mobile')
            ->orderByDesc('total')
            ->limit($limit)
            ->get();
    }

    /* ------------------------------------------------------------ payment */

    /**
     * Collections by method - the SRS's payment-method report.
     *
     * @return Collection<int, object>
     */
    public function paymentsByMethod(ReportFilters $range): Collection
    {
        return Payment::query()
            ->effective()
            ->incoming()
            ->whereBetween('paid_at', [$range->from, $range->to])
            ->selectRaw('method')
            ->selectRaw('COUNT(*) as entries')
            ->selectRaw('COALESCE(SUM(amount), 0) as total')
            ->groupBy('method')
            ->orderByDesc('total')
            ->get();
    }

    /* ---------------------------------------------------------------- tax */

    /**
     * Tax collected, grouped by HSN and rate - the shape a GST return wants.
     *
     * @return Collection<int, object>
     */
    public function taxByHsn(ReportFilters $range): Collection
    {
        return $this->lineBase($range)
            ->selectRaw("COALESCE(NULLIF(invoice_items.hsn_code, ''), '—') as hsn")
            ->selectRaw('invoice_items.tax_rate')
            ->selectRaw('SUM(invoice_items.taxable_value) as taxable')
            ->selectRaw('SUM(invoice_items.cgst_amount) as cgst')
            ->selectRaw('SUM(invoice_items.sgst_amount) as sgst')
            ->selectRaw('SUM(invoice_items.igst_amount) as igst')
            ->selectRaw('SUM(invoice_items.cess_amount) as cess')
            ->groupBy('hsn', 'invoice_items.tax_rate')
            ->orderBy('hsn')
            ->get();
    }

    /**
     * Tax paid on purchases - the input side of the same return.
     *
     * @return Collection<int, object>
     */
    public function inputTax(ReportFilters $range): Collection
    {
        return GoodsReceiptItem::query()
            ->join('goods_receipts', 'goods_receipts.id', '=', 'goods_receipt_items.goods_receipt_id')
            ->whereIn('goods_receipts.shop_id', $this->shopIds())
            ->where('goods_receipts.status', GoodsReceipt::POSTED)
            ->whereBetween('goods_receipts.received_on', [$range->from, $range->to])
            ->selectRaw('goods_receipt_items.tax_rate')
            ->selectRaw('SUM(goods_receipt_items.taxable_value) as taxable')
            ->selectRaw('SUM(goods_receipt_items.cgst_amount) as cgst')
            ->selectRaw('SUM(goods_receipt_items.sgst_amount) as sgst')
            ->selectRaw('SUM(goods_receipt_items.igst_amount) as igst')
            ->groupBy('goods_receipt_items.tax_rate')
            ->orderBy('goods_receipt_items.tax_rate')
            ->get();
    }

    /* ----------------------------------------------------------- purchase */

    /**
     * @return Collection<int, object>
     */
    public function purchasesBySupplier(ReportFilters $range): Collection
    {
        return GoodsReceipt::query()
            ->counted()
            ->whereBetween('received_on', [$range->from, $range->to])
            ->join('suppliers', 'suppliers.id', '=', 'goods_receipts.supplier_id')
            ->selectRaw("COALESCE(NULLIF(suppliers.company, ''), suppliers.name) as supplier")
            ->selectRaw('COUNT(*) as receipts')
            ->selectRaw('COALESCE(SUM(goods_receipts.subtotal), 0) as goods')
            ->selectRaw('COALESCE(SUM(goods_receipts.tax_total), 0) as tax')
            ->selectRaw('COALESCE(SUM(goods_receipts.grand_total), 0) as total')
            ->selectRaw('COALESCE(SUM(goods_receipts.due_total), 0) as outstanding')
            ->groupBy('supplier')
            ->orderByDesc('total')
            ->get();
    }

    /**
     * @return array<string, float|int>
     */
    public function purchaseSummary(ReportFilters $range): array
    {
        $row = GoodsReceipt::query()
            ->counted()
            ->whereBetween('received_on', [$range->from, $range->to])
            ->selectRaw('COUNT(*) as receipts')
            ->selectRaw('COALESCE(SUM(subtotal), 0) as goods')
            ->selectRaw('COALESCE(SUM(tax_total), 0) as tax')
            ->selectRaw('COALESCE(SUM(other_charges), 0) as charges')
            ->selectRaw('COALESCE(SUM(grand_total), 0) as total')
            ->selectRaw('COALESCE(SUM(due_total), 0) as outstanding')
            ->first();

        return [
            'receipts' => (int) $row->receipts,
            'goods' => (float) $row->goods,
            'tax' => (float) $row->tax,
            'charges' => (float) $row->charges,
            'total' => (float) $row->total,
            'outstanding' => (float) $row->outstanding,
        ];
    }

    /* -------------------------------------------------------------- stock */

    /**
     * What is on the shelf and what it is worth.
     *
     * @return Builder
     */
    public function stockValuation(): Builder
    {
        return ProductStock::query()
            ->with(['product:id,name,sku,unit_id,reorder_level', 'product.unit:id,code',
                'warehouse:id,name', 'batch:id,batch_no,expiry_date'])
            ->where('quantity', '!=', 0)
            ->orderByRaw('quantity * average_cost DESC');
    }

    /**
     * Products at or below their reorder level.
     *
     * @return Builder
     */
    public function lowStock(): Builder
    {
        $list = implode(',', array_map('intval', $this->shopIds()));

        return Product::query()
            ->active()
            // Dishes are not stocked - see Product::tracksStock().
            ->where('is_made_to_order', false)
            ->where('reorder_level', '>', 0)
            ->withSum(['stocks as on_hand' => fn ($q) => $q->whereIn('shop_id', $this->shopIds())], 'quantity')
            ->whereRaw(
                '(SELECT COALESCE(SUM(quantity), 0) FROM product_stocks
                    WHERE product_stocks.product_id = products.id
                    AND product_stocks.shop_id IN ('.$list.')
                 ) <= products.reorder_level'
            )
            ->orderBy('name');
    }

    /**
     * Batches expiring soon, or already gone, that still hold stock.
     *
     * @return Builder
     */
    public function expiring(int $days): Builder
    {
        return Batch::query()
            ->with(['product:id,name,sku', 'shop:id,name'])
            ->withSum('stocks as on_hand', 'quantity')
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', today()->addDays($days))
            ->whereHas('stocks', fn ($q) => $q->where('quantity', '>', 0))
            ->fefo();
    }

    /* --------------------------------------------------------------- dues */

    /**
     * Outstanding by customer, with the oldest unpaid invoice's age.
     *
     * @return Builder
     */
    public function outstanding(): Builder
    {
        return Customer::query()
            ->withDues()
            ->orderByDesc('balance');
    }

    /**
     * What the shop owes its suppliers.
     *
     * @return Builder
     */
    public function payables(): Builder
    {
        return Supplier::query()
            ->withPayables()
            ->orderByDesc('balance');
    }

    /* ------------------------------------------------------------ returns */

    /**
     * What came back, both ways (SRS 13).
     *
     * Sales returns and purchase returns on one screen because the question
     * a shop asks is "what did we take back and what did we send back", and
     * splitting them across two reports means neither gets opened.
     *
     * @return Collection<int, object>
     */
    public function returnsByDay(ReportFilters $range): Collection
    {
        /*
         | The two sides are summed separately and then stitched by date,
         | rather than joined in SQL. A join on date would multiply the rows
         | on any day that had both - the classic fan-out that quietly
         | doubles a total nobody re-checks.
         */
        $salesRows = SalesReturn::query()
            ->whereNotIn('status', [SalesReturn::DRAFT, SalesReturn::CANCELLED])
            ->whereBetween('returned_on', [$range->from->toDateString(), $range->to->toDateString()])
            ->selectRaw('DATE(returned_on) as period')
            ->selectRaw('COUNT(*) as documents')
            ->selectRaw('COALESCE(SUM(grand_total), 0) as value')
            ->selectRaw('COALESCE(SUM(refund_amount), 0) as refunded')
            ->groupBy('period')
            ->get()
            ->keyBy('period');

        $purchaseRows = PurchaseReturn::query()
            ->whereNotIn('status', [PurchaseReturn::DRAFT, PurchaseReturn::CANCELLED])
            ->whereBetween('returned_on', [$range->from->toDateString(), $range->to->toDateString()])
            ->selectRaw('DATE(returned_on) as period')
            ->selectRaw('COUNT(*) as documents')
            ->selectRaw('COALESCE(SUM(grand_total), 0) as value')
            ->groupBy('period')
            ->get()
            ->keyBy('period');

        return $salesRows->keys()
            ->merge($purchaseRows->keys())
            ->unique()
            ->sort()
            ->values()
            ->map(function (string $period) use ($salesRows, $purchaseRows) {
                $in = $salesRows->get($period);
                $out = $purchaseRows->get($period);

                return (object) [
                    'period' => $period,
                    'sales_returns' => (int) ($in->documents ?? 0),
                    'sales_value' => (float) ($in->value ?? 0),
                    'refunded' => (float) ($in->refunded ?? 0),
                    'purchase_returns' => (int) ($out->documents ?? 0),
                    'purchase_value' => (float) ($out->value ?? 0),
                ];
            });
    }

    /**
     * @return array<string, float|int>
     */
    public function returnsSummary(ReportFilters $range): array
    {
        $rows = $this->returnsByDay($range);

        $sold = (float) $this->salesTotals($range->from, $range->to)['total'];
        $returned = (float) $rows->sum('sales_value');

        return [
            'sales_returns' => (int) $rows->sum('sales_returns'),
            'sales_value' => round($returned, 2),
            'refunded' => round((float) $rows->sum('refunded'), 2),
            'purchase_returns' => (int) $rows->sum('purchase_returns'),
            'purchase_value' => round((float) $rows->sum('purchase_value'), 2),
            // The figure that actually means something: returns as a share of
            // what went out. A rupee total on its own says nothing about
            // whether it is a lot.
            'return_rate' => $sold > 0 ? round($returned / $sold * 100, 2) : 0.0,
        ];
    }

    /* ---------------------------------------------------------- financial */

    /**
     * Income against outgoings, month by month (SRS 13).
     *
     * Cash-basis on the collection side and accrual on the sales side, and
     * the report shows both rather than picking one - a shop wants to know
     * what it billed *and* what it actually banked, and the gap between them
     * is the credit book.
     *
     * @return Collection<int, object>
     */
    public function financeByMonth(ReportFilters $range): Collection
    {
        $months = [];

        $cursor = $range->from->copy()->startOfMonth();

        while ($cursor->lte($range->to)) {
            $months[$cursor->format('Y-m')] = [
                'period' => $cursor->format('Y-m'),
                'label' => $cursor->format('M Y'),
                'revenue' => 0.0,
                'cost' => 0.0,
                'tax' => 0.0,
                'collected' => 0.0,
                'purchases' => 0.0,
                'expenses' => 0.0,
            ];

            $cursor->addMonth();
        }

        $stamp = fn ($date) => Carbon::parse($date)->format('Y-m');

        foreach ($this->salesBase($range)
            ->selectRaw('DATE(invoiced_at) as day')
            ->selectRaw('COALESCE(SUM(subtotal), 0) as revenue')
            ->selectRaw('COALESCE(SUM(cost_total), 0) as cost')
            ->selectRaw('COALESCE(SUM(tax_total), 0) as tax')
            ->selectRaw('COALESCE(SUM(paid_total), 0) as collected')
            ->groupBy('day')->get() as $row) {
            $key = $stamp($row->day);

            if (! isset($months[$key])) {
                continue;
            }

            $months[$key]['revenue'] += (float) $row->revenue;
            $months[$key]['cost'] += (float) $row->cost;
            $months[$key]['tax'] += (float) $row->tax;
            $months[$key]['collected'] += (float) $row->collected;
        }

        foreach (GoodsReceipt::query()
            ->counted()
            ->whereBetween('received_on', [$range->from, $range->to])
            ->selectRaw('DATE(received_on) as day')
            ->selectRaw('COALESCE(SUM(grand_total), 0) as total')
            ->groupBy('day')->get() as $row) {
            $key = $stamp($row->day);

            if (isset($months[$key])) {
                $months[$key]['purchases'] += (float) $row->total;
            }
        }

        foreach (Expense::query()
            ->whereBetween('spent_on', [$range->from->toDateString(), $range->to->toDateString()])
            ->selectRaw('DATE(spent_on) as day')
            ->selectRaw('COALESCE(SUM(amount), 0) as total')
            ->groupBy('day')->get() as $row) {
            $key = $stamp($row->day);

            if (isset($months[$key])) {
                $months[$key]['expenses'] += (float) $row->total;
            }
        }

        return collect($months)->values()->map(function (array $row) {
            $row['gross'] = round($row['revenue'] - $row['cost'], 2);
            $row['net'] = round($row['gross'] - $row['expenses'], 2);

            return (object) $row;
        });
    }

    /**
     * @return array<string, float|int>
     */
    public function financeSummary(ReportFilters $range): array
    {
        $rows = $this->financeByMonth($range);

        $revenue = (float) $rows->sum('revenue');
        $cost = (float) $rows->sum('cost');
        $expenses = (float) $rows->sum('expenses');

        return [
            'revenue' => round($revenue, 2),
            'cost' => round($cost, 2),
            'gross' => round($revenue - $cost, 2),
            'expenses' => round($expenses, 2),
            'net' => round($revenue - $cost - $expenses, 2),
            'collected' => round((float) $rows->sum('collected'), 2),
            'purchases' => round((float) $rows->sum('purchases'), 2),
            'tax' => round((float) $rows->sum('tax'), 2),
            // Margin on nothing is undefined, not zero - printing "0%" on a
            // month with no sales is how a report gets mistrusted.
            'margin' => $revenue > 0 ? round(($revenue - $cost) / $revenue * 100, 2) : null,
        ];
    }

    /* ---------------------------------------------------------- transfers */

    /**
     * Stock moved between branches, in the period (SRS 13).
     *
     * Read across shops rather than through the usual scope, because a
     * transfer has two sides and only one of them carries `shop_id`. Filtered
     * to the branches the reader may see on either side, so a transfer with
     * one end outside their reach still shows - it is their stock leaving or
     * arriving, and hiding it would make the shortfall unexplainable.
     *
     * @return Builder
     */
    public function stockTransfers(ReportFilters $range): Builder
    {
        $shops = $this->shopIds();

        return StockTransfer::allShops()
            ->with(['fromWarehouse:id,name', 'toWarehouse:id,name', 'shop:id,name', 'toShop:id,name'])
            ->where(fn (Builder $q) => $q
                ->whereIn('shop_id', $shops)
                ->orWhereIn('to_shop_id', $shops))
            ->whereNotIn('status', [StockTransfer::DRAFT, StockTransfer::CANCELLED])
            ->whereBetween('transfer_date', [$range->from->toDateString(), $range->to->toDateString()])
            ->orderByDesc('transfer_date')
            ->orderByDesc('id');
    }

    /**
     * @return array<string, float|int>
     */
    public function transferSummary(ReportFilters $range): array
    {
        $base = fn () => $this->stockTransfers($range);

        $inFlight = (clone $base())->where('status', StockTransfer::DISPATCHED);

        return [
            'transfers' => $base()->count(),
            'quantity' => round((float) $base()->sum('total_quantity'), 3),
            'value' => round((float) $base()->sum('total_value'), 2),
            'received' => (clone $base())->where('status', StockTransfer::RECEIVED)->count(),
            // The number a stock controller actually needs: goods that are on
            // the van and therefore in neither warehouse.
            'in_transit' => $inFlight->count(),
            'in_transit_value' => round((float) $inFlight->sum('total_value'), 2),
            'pending' => (clone $base())
                ->whereIn('status', [StockTransfer::PENDING, StockTransfer::APPROVED])
                ->count(),
        ];
    }

    /* ------------------------------------------------------- day closing */

    /**
     * The day book: what the till expected against what was counted (SRS 13).
     *
     * @return Builder
     */
    public function dayClosings(ReportFilters $range): Builder
    {
        return CashRegister::query()
            ->with(['shop:id,name', 'closedBy:id,name'])
            ->whereBetween('business_date', [$range->from->toDateString(), $range->to->toDateString()])
            ->orderByDesc('business_date')
            ->orderByDesc('id');
    }

    /**
     * @return array<string, float|int>
     */
    public function dayCloseSummary(ReportFilters $range): array
    {
        $base = fn () => CashRegister::query()
            ->whereBetween('business_date', [$range->from->toDateString(), $range->to->toDateString()]);

        $row = $base()
            ->selectRaw('COUNT(*) as days')
            ->selectRaw('COALESCE(SUM(expected_cash), 0) as expected')
            ->selectRaw('COALESCE(SUM(counted_cash), 0) as counted')
            // Over and short are summed apart on purpose. Netting them lets a
            // day 500 over cancel a day 500 short and report a balanced week
            // that never happened.
            ->selectRaw('COALESCE(SUM(CASE WHEN variance > 0 THEN variance ELSE 0 END), 0) as over')
            ->selectRaw('COALESCE(SUM(CASE WHEN variance < 0 THEN -variance ELSE 0 END), 0) as short')
            ->first();

        return [
            'days' => (int) $row->days,
            'expected' => round((float) $row->expected, 2),
            'counted' => round((float) $row->counted, 2),
            'over' => round((float) $row->over, 2),
            'short' => round((float) $row->short, 2),
            'open' => $base()->where('status', CashRegister::OPEN)->count(),
            'unapproved' => $base()->where('status', CashRegister::CLOSED)->count(),
        ];
    }

    /* ------------------------------------------------------------ helpers */

    /**
     * Invoices that count as sales, in the range.
     */
    private function salesBase(ReportFilters $range): Builder
    {
        return Invoice::query()
            ->counted()
            // Qualified for the same reason scopeCounted is: several reports
            // join this to tables that carry their own dates.
            ->whereBetween('invoices.invoiced_at', [$range->from, $range->to]);
    }

    /**
     * Invoice lines that count as sales, in the range.
     *
     * A plain query builder rather than Eloquent: invoice_items is not
     * shop-scoped on its own - it has no shop_id - so the scope is applied
     * through the join, which is the only correct place for it.
     */
    private function lineBase(ReportFilters $range): \Illuminate\Database\Query\Builder
    {
        return InvoiceItem::query()
            ->getQuery()
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->whereIn('invoices.shop_id', $this->shopIds())
            ->whereNotIn('invoices.status', [Invoice::DRAFT, Invoice::CANCELLED])
            ->whereBetween('invoices.invoiced_at', [$range->from, $range->to]);
    }

    /* ------------------------------------------------------- the kitchen */

    /**
     * How long each station took, over the period (§9).
     *
     * Two different waits, kept apart because they are two different
     * problems and the fix for each is a different person's:
     *
     *   to accept   how long a ticket sat before anybody picked it up -
     *               a staffing or an attention problem
     *   to cook     accepted to on-the-pass - a recipe, a prep or an
     *               equipment problem
     *
     * A single "average time" would add them together and hide whichever one
     * is actually wrong.
     *
     * Read off `order_items` rather than `orders`, because with routing on a
     * ticket is several jobs and a per-order average would tell the bar it is
     * slow when the tandoor is. Lines the kitchen never finished are excluded
     * from the averages entirely - an unfinished line has no duration, and
     * counting it as zero would make a backed-up kitchen look fast.
     *
     * @return Collection<int, object>
     */
    public function kitchenPerformance(ReportFilters $range): Collection
    {
        $accepted = $this->seconds('COALESCE(orders.placed_at, orders.created_at)', 'orders.accepted_at');
        $cooked = $this->seconds(
            'COALESCE(order_items.kitchen_started_at, orders.placed_at, orders.created_at)',
            'order_items.kitchen_ready_at',
        );
        $onPass = $this->seconds('COALESCE(orders.placed_at, orders.created_at)', 'order_items.kitchen_ready_at');

        return OrderItem::query()
            ->getQuery()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('kitchen_stations', 'kitchen_stations.id', '=', 'order_items.kitchen_station_id')
            ->whereIn('orders.shop_id', $this->shopIds())
            ->whereNotNull('order_items.kitchen_status')
            ->whereBetween(DB::raw('COALESCE(orders.placed_at, orders.created_at)'), [$range->from, $range->to])
            ->selectRaw("COALESCE(kitchen_stations.name, 'Unrouted') as station")
            ->selectRaw('COUNT(*) as lines_made')
            ->selectRaw('COUNT(DISTINCT orders.id) as tickets')
            ->selectRaw('SUM(order_items.quantity) as quantity')
            // Seconds rather than minutes, so a forty-second drink does not
            // average to zero. Formatted for reading in the view.

            ->selectRaw("AVG(CASE WHEN orders.accepted_at IS NOT NULL THEN {$accepted} END) as accept_seconds")
            ->selectRaw("AVG(CASE WHEN order_items.kitchen_ready_at IS NOT NULL THEN {$cooked} END) as cook_seconds")
            ->selectRaw("MAX(CASE WHEN order_items.kitchen_ready_at IS NOT NULL THEN {$cooked} END) as worst_seconds")
            ->selectRaw('SUM(CASE WHEN order_items.kitchen_ready_at IS NULL THEN 1 ELSE 0 END) as unfinished')
            /*
             | Late is judged against the station's own window, so a bar and a
             | tandoor are not held to the same number - which is the same rule
             | the board colours its cards by.
             */
            ->selectRaw("SUM(CASE WHEN order_items.kitchen_ready_at IS NOT NULL
                AND {$onPass} > COALESCE(kitchen_stations.prep_minutes, 15) * 60
                THEN 1 ELSE 0 END) as late")
            ->groupBy('station')
            ->orderByDesc('lines_made')
            ->get();
    }

    /**
     * Seconds between two datetime columns, in this connection's dialect.
     *
     * Subtracting two DATETIMEs is not portable and, on MySQL, is not even
     * meaningful - it yields a number like 20260916055027, which is not a
     * duration in any unit. Every engine has a function for this and no two
     * of them agree on its name.
     *
     * This exists because the application runs on MySQL and the test suite
     * runs on SQLite, so a report written in one dialect is a report that is
     * never tested. The inputs are column expressions this class builds, never
     * anything a request supplied.
     */
    private function seconds(string $from, string $to): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "(strftime('%s', {$to}) - strftime('%s', {$from}))",
            'pgsql' => "EXTRACT(EPOCH FROM ({$to} - {$from}))",
            default => "TIMESTAMPDIFF(SECOND, {$from}, {$to})",
        };
    }

    /**
     * The shops this reader may report on.
     *
     * @return array<int, int>
     */
    private function shopIds(): array
    {
        return CurrentShop::id() !== null
            ? [CurrentShop::id()]
            : (CurrentShop::accessibleIds() ?: [0]);
    }
}
