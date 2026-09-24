<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Batch;
use App\Services\ReportService;
use App\Support\CurrentShop;
use App\Support\ReportFilters;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The reports.
 *
 * One controller for all of them because they share everything that
 * matters: the date range, the shop scope, the export shape and the
 * permission pattern. Splitting them into a dozen classes would mean a
 * dozen copies of the same twelve lines.
 *
 * Each report declares what it is once, in REPORTS below, and the index,
 * the routing, the permission check and the CSV export all read from that.
 */
class ReportController extends Controller
{
    /**
     * Every report, and what it needs.
     *
     * `dated` says whether the report is about a period or about right now:
     * a sales report is the former, a stock valuation the latter, and
     * offering a date range on a stock report would promise history the
     * system does not keep.
     *
     * @var array<string, array<string, mixed>>
     */
    private const REPORTS = [
        'sales' => [
            'label' => 'Sales',
            'blurb' => 'Day by day, with tax, collections and margin.',
            'permission' => 'reports.sales_report',
            'icon' => 'cart',
            'dated' => true,
        ],
        'profit' => [
            'label' => 'Profit',
            'blurb' => 'Revenue against the cost captured when each sale was made.',
            'permission' => 'reports.profit_report',
            'icon' => 'trend-up',
            'dated' => true,
        ],
        'products' => [
            'label' => 'Product Sales',
            'blurb' => 'What sold, how much of it, and at what margin.',
            'permission' => 'reports.sales_report',
            'icon' => 'package',
            'dated' => true,
        ],
        'categories' => [
            'label' => 'Category Sales',
            'blurb' => 'The same, grouped the way the catalogue is.',
            'permission' => 'reports.sales_report',
            'icon' => 'grid',
            'dated' => true,
        ],
        'employees' => [
            'label' => 'Employee Sales',
            'blurb' => 'Who rang up what.',
            'permission' => 'reports.employee_report',
            'icon' => 'users',
            'dated' => true,
        ],
        'shops' => [
            'label' => 'Shop Sales',
            'blurb' => 'Branch against branch. Only meaningful across shops.',
            'permission' => 'reports.sales_report',
            'icon' => 'building',
            'dated' => true,
        ],
        'payments' => [
            'label' => 'Payment Methods',
            'blurb' => 'What was collected, and how.',
            'permission' => 'reports.payment_report',
            'icon' => 'wallet',
            'dated' => true,
        ],
        'tax' => [
            'label' => 'Tax / GST',
            'blurb' => 'Output tax by HSN and rate, with input tax alongside.',
            'permission' => 'reports.tax_report',
            'icon' => 'file',
            'dated' => true,
        ],
        'purchases' => [
            'label' => 'Purchases',
            'blurb' => 'What was bought, from whom, and what is still owed.',
            'permission' => 'reports.purchase_report',
            'icon' => 'truck',
            'dated' => true,
        ],
        'stock' => [
            'label' => 'Stock & Valuation',
            'blurb' => 'What is on the shelf now, at weighted average cost.',
            'permission' => 'reports.stock_report',
            'icon' => 'package',
            'dated' => false,
        ],
        'low-stock' => [
            'label' => 'Low Stock',
            'blurb' => 'Products at or below their reorder level.',
            'permission' => 'reports.expiry_report',
            'icon' => 'trend-down',
            'dated' => false,
        ],
        'expiry' => [
            'label' => 'Expiry',
            'blurb' => 'Batches expiring soon, or already gone, that still hold stock.',
            'permission' => 'reports.expiry_report',
            'icon' => 'clock',
            'dated' => false,
        ],
        'outstanding' => [
            'label' => 'Outstanding',
            'blurb' => 'Who owes the shop, and how much.',
            'permission' => 'reports.dues_report',
            'icon' => 'users',
            'dated' => false,
        ],
        'payables' => [
            'label' => 'Supplier Payables',
            'blurb' => 'What the shop owes, and to whom.',
            'permission' => 'reports.supplier_report',
            'icon' => 'truck',
            'dated' => false,
        ],

        'returns' => [
            'label' => 'Returns & Refunds',
            'blurb' => 'What came back over the counter, and what went back to suppliers.',
            'permission' => 'reports.returns_report',
            'icon' => 'trend-down',
            'dated' => true,
        ],
        'finance' => [
            'label' => 'Financial Summary',
            'blurb' => 'Revenue, cost, expenses and what is left, month by month.',
            'permission' => 'reports.finance_report',
            'icon' => 'wallet',
            'dated' => true,
        ],
        'transfers' => [
            'label' => 'Stock Transfers',
            'blurb' => 'Stock moved between branches, and what is still on the van.',
            'permission' => 'reports.stock_report',
            'icon' => 'truck',
            'dated' => true,
        ],
        'day-closing' => [
            'label' => 'Day Closing',
            'blurb' => 'Expected against counted, day by day, with over and short kept apart.',
            'permission' => 'reports.payment_report',
            'icon' => 'wallet',
            'dated' => true,
        ],
        'kitchen' => [
            'label' => 'Kitchen Performance',
            'blurb' => 'How long each station took, with the wait to accept kept apart from the time to cook.',
            'permission' => 'reports.kitchen_report',
            'icon' => 'clock',
            'dated' => true,
        ],
        'tables' => [
            'label' => 'Table Sales',
            'blurb' => 'Which tables earn, by covers and by average bill. Dine-in only.',
            'permission' => 'reports.sales_report',
            'icon' => 'grid',
            'dated' => true,
        ],
        'discounts' => [
            'label' => 'Discounts & Coupons',
            'blurb' => 'What was not charged, with line discounts kept apart from bill discounts.',
            'permission' => 'reports.sales_report',
            'icon' => 'tag',
            'dated' => true,
        ],
        'cancelled' => [
            'label' => 'Cancelled Orders',
            'blurb' => 'Every cancellation, with its reason and who made it.',
            'permission' => 'reports.sales_report',
            'icon' => 'x',
            'dated' => true,
        ],
        'customers' => [
            'label' => 'Customer History',
            'blurb' => 'Who comes back, how often, and what they spend.',
            'permission' => 'reports.sales_report',
            'icon' => 'users',
            'dated' => true,
        ],

    ];

    public function __construct(private readonly ReportService $reports) {}

    /* -------------------------------------------------------------- index */

    /** The menu of reports this user may open. */
    public function index(): View
    {
        return view('admin.reports.index', [
            'reports' => collect(self::REPORTS)
                ->filter(fn (array $meta) => auth()->user()->can($meta['permission'].'.view')),
        ]);
    }

    /* ------------------------------------------------------------- report */

    public function show(Request $request, string $report): View
    {
        $meta = $this->meta($report);

        abort_unless(auth()->user()->can($meta['permission'].'.view'), 403);

        $range = ReportFilters::fromRequest($request);
        $days = (int) $request->integer('days', Batch::NEAR_EXPIRY_DAYS);

        return view('admin.reports.show', [
            'key' => $report,
            'meta' => $meta,
            'range' => $range,
            'days' => $days,
            'presets' => ReportFilters::PRESETS,
            'canExport' => auth()->user()->can($meta['permission'].'.export'),
            'showsShop' => CurrentShop::id() === null,
            'data' => $this->build($report, $range, $days),
        ]);
    }

    /**
     * Everything the report's view needs.
     *
     * @return array<string, mixed>
     */
    private function build(string $report, ReportFilters $range, int $days): array
    {
        return match ($report) {
            'sales' => [
                'summary' => $this->reports->salesSummary($range),
                'rows' => $this->reports->salesByDay($range),
            ],
            'profit' => [
                'summary' => $this->reports->salesSummary($range),
                'rows' => $this->reports->salesByDay($range),
                'products' => $this->reports->salesByProduct($range, 25),
            ],
            'products' => ['rows' => $this->reports->salesByProduct($range)],
            'categories' => ['rows' => $this->reports->salesByCategory($range)],
            'employees' => ['rows' => $this->reports->salesByEmployee($range)],
            'shops' => ['rows' => $this->reports->salesByShop($range)],
            'payments' => ['rows' => $this->reports->paymentsByMethod($range)],
            'tax' => [
                'rows' => $this->reports->taxByHsn($range),
                'input' => $this->reports->inputTax($range),
            ],
            'purchases' => [
                'summary' => $this->reports->purchaseSummary($range),
                'rows' => $this->reports->purchasesBySupplier($range),
            ],
            'stock' => ['rows' => $this->reports->stockValuation()->limit(500)->get()],
            'low-stock' => ['rows' => $this->reports->lowStock()->limit(500)->get()],
            'expiry' => ['rows' => $this->reports->expiring($days)->limit(500)->get()],
            'outstanding' => ['rows' => $this->reports->outstanding()->limit(500)->get()],
            'payables' => ['rows' => $this->reports->payables()->limit(500)->get()],

            'returns' => [
                'summary' => $this->reports->returnsSummary($range),
                'rows' => $this->reports->returnsByDay($range),
            ],
            'finance' => [
                'summary' => $this->reports->financeSummary($range),
                'rows' => $this->reports->financeByMonth($range),
            ],
            'transfers' => [
                'summary' => $this->reports->transferSummary($range),
                'rows' => $this->reports->stockTransfers($range)->limit(500)->get(),
            ],
            'day-closing' => [
                'summary' => $this->reports->dayCloseSummary($range),
                'rows' => $this->reports->dayClosings($range)->limit(500)->get(),
            ],
            'kitchen' => ['rows' => $this->reports->kitchenPerformance($range)],

            'tables' => ['rows' => $this->reports->salesByTable($range)],
            'cancelled' => ['rows' => $this->reports->cancelledOrders($range)],
            'customers' => ['rows' => $this->reports->customerHistory($range)],
            /*
             | Two tables on one screen. They are the same question asked
             | twice - how much did we not charge, and which offer caused it -
             | and splitting them across two reports would mean nobody ever
             | read the second.
             */
            'discounts' => [
                'rows' => $this->reports->discountsByDay($range),
                'coupons' => $this->reports->couponsRedeemed($range),
            ],

            default => ['rows' => collect()],
        };
    }

    /* ------------------------------------------------------------ export */

    /**
     * The same report as CSV.
     *
     * Streamed and unlimited, unlike the screen: an export is for a
     * spreadsheet, and truncating it silently at 500 rows would be a lie
     * about the data.
     */
    public function export(Request $request, string $report): StreamedResponse
    {
        $meta = $this->meta($report);

        abort_unless(auth()->user()->can($meta['permission'].'.export'), 403);

        $range = ReportFilters::fromRequest($request);
        $days = (int) $request->integer('days', Batch::NEAR_EXPIRY_DAYS);

        [$columns, $rows] = $this->exportRows($report, $range, $days);

        $filename = $report.'-'.($meta['dated'] ? $range->slug() : today()->format('Ymd')).'.csv';

        ActivityLog::record(
            'report.exported',
            sprintf('Exported the %s report (%s)', $meta['label'], $meta['dated'] ? $range->label() : 'as at today'),
        );

        return response()->streamDownload(function () use ($columns, $rows) {
            $handle = fopen('php://output', 'wb');

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $columns);

            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Columns and rows for one report's CSV.
     *
     * The uncapped sets stream with lazy(), not cursor(): cursor() hydrates
     * one row at a time and never runs the builder's eager loads, so every
     * product, warehouse and batch a line names becomes its own query on the
     * one path with no row limit at all. lazy() keeps the streaming and
     * batches the relations.
     *
     * @return array{0: array<int, string>, 1: iterable<int, array<int, mixed>>}
     */
    private function exportRows(string $report, ReportFilters $range, int $days): array
    {
        $money = fn ($value) => number_format((float) $value, 2, '.', '');
        $qty = fn ($value) => number_format((float) $value, 3, '.', '');

        return match ($report) {
            'sales', 'profit' => [
                ['Date', 'Invoices', 'Taxable', 'Tax', 'Total', 'Collected', 'Cost', 'Gross profit'],
                $this->reports->salesByDay($range)->map(fn ($r) => [
                    $r->period,
                    $r->invoices,
                    $money($r->taxable),
                    $money($r->tax),
                    $money($r->total),
                    $money($r->collected),
                    $money($r->cost),
                    $money((float) $r->taxable - (float) $r->cost),
                ]),
            ],

            'products' => [
                ['SKU', 'Product', 'Quantity', 'Revenue', 'Cost', 'Gross profit', 'Margin %'],
                $this->reports->salesByProduct($range, 100000)->map(function ($r) use ($money, $qty) {
                    $profit = (float) $r->revenue - (float) $r->cost;

                    return [
                        $r->sku,
                        $r->product_name,
                        $qty($r->quantity),
                        $money($r->revenue),
                        $money($r->cost),
                        $money($profit),
                        (float) $r->revenue > 0
                            ? number_format($profit / (float) $r->revenue * 100, 2, '.', '')
                            : '',
                    ];
                }),
            ],

            'categories' => [
                ['Category', 'Quantity', 'Revenue', 'Cost', 'Gross profit'],
                $this->reports->salesByCategory($range)->map(fn ($r) => [
                    $r->category,
                    $qty($r->quantity),
                    $money($r->revenue),
                    $money($r->cost),
                    $money((float) $r->revenue - (float) $r->cost),
                ]),
            ],

            'employees' => [
                ['Person', 'Invoices', 'Sales', 'Gross profit'],
                $this->reports->salesByEmployee($range)->map(fn ($r) => [
                    $r->person, $r->invoices, $money($r->total), $money($r->profit),
                ]),
            ],

            'shops' => [
                ['Shop', 'Invoices', 'Sales', 'Gross profit'],
                $this->reports->salesByShop($range)->map(fn ($r) => [
                    $r->shop, $r->invoices, $money($r->total), $money($r->profit),
                ]),
            ],

            'payments' => [
                ['Method', 'Entries', 'Collected'],
                $this->reports->paymentsByMethod($range)->map(fn ($r) => [
                    \App\Models\Payment::METHODS[$r->method]['label'] ?? $r->method,
                    $r->entries,
                    $money($r->total),
                ]),
            ],

            'tax' => [
                ['HSN', 'Rate %', 'Taxable', 'CGST', 'SGST', 'IGST', 'Cess', 'Total tax'],
                $this->reports->taxByHsn($range)->map(fn ($r) => [
                    $r->hsn,
                    number_format((float) $r->tax_rate, 2, '.', ''),
                    $money($r->taxable),
                    $money($r->cgst),
                    $money($r->sgst),
                    $money($r->igst),
                    $money($r->cess),
                    $money((float) $r->cgst + (float) $r->sgst + (float) $r->igst + (float) $r->cess),
                ]),
            ],

            'purchases' => [
                ['Supplier', 'Receipts', 'Goods', 'Tax', 'Total', 'Outstanding'],
                $this->reports->purchasesBySupplier($range)->map(fn ($r) => [
                    $r->supplier, $r->receipts, $money($r->goods), $money($r->tax),
                    $money($r->total), $money($r->outstanding),
                ]),
            ],

            'stock' => [
                ['SKU', 'Product', 'Warehouse', 'Batch', 'Expiry', 'On hand', 'Avg cost', 'Value'],
                $this->reports->stockValuation()->lazy(500)->map(fn ($r) => [
                    $r->product?->sku,
                    $r->product?->name,
                    $r->warehouse?->name,
                    $r->batch?->batch_no,
                    $r->batch?->expiry_date?->format('Y-m-d'),
                    $qty($r->quantity),
                    $money($r->average_cost),
                    $money($r->value()),
                ]),
            ],

            'low-stock' => [
                ['SKU', 'Product', 'On hand', 'Reorder level', 'Short by'],
                $this->reports->lowStock()->lazy(500)->map(fn ($r) => [
                    $r->sku,
                    $r->name,
                    $qty($r->on_hand ?? 0),
                    $qty($r->reorder_level),
                    $qty(max(0, (float) $r->reorder_level - (float) ($r->on_hand ?? 0))),
                ]),
            ],

            'expiry' => [
                ['SKU', 'Product', 'Batch', 'Expiry', 'Days left', 'On hand'],
                $this->reports->expiring($days)->lazy(500)->map(fn ($r) => [
                    $r->product?->sku,
                    $r->product?->name,
                    $r->batch_no,
                    $r->expiry_date?->format('Y-m-d'),
                    $r->daysToExpiry(),
                    $qty($r->on_hand ?? 0),
                ]),
            ],

            'outstanding' => [
                ['Code', 'Customer', 'Mobile', 'Village', 'Credit limit', 'Owes'],
                $this->reports->outstanding()->lazy(500)->map(fn ($r) => [
                    $r->code, $r->name, $r->mobile, $r->village,
                    $money($r->credit_limit), $money($r->balance),
                ]),
            ],

            'payables' => [
                ['Code', 'Supplier', 'Mobile', 'Terms', 'We owe'],
                $this->reports->payables()->lazy(500)->map(fn ($r) => [
                    $r->code, $r->displayName(), $r->mobile,
                    $r->credit_days.' days', $money($r->balance),
                ]),
            ],

            'returns' => [
                ['Date', 'Sales returns', 'Value taken back', 'Refunded', 'Purchase returns', 'Value sent back'],
                $this->reports->returnsByDay($range)->map(fn ($r) => [
                    $r->period,
                    $r->sales_returns,
                    $money($r->sales_value),
                    $money($r->refunded),
                    $r->purchase_returns,
                    $money($r->purchase_value),
                ]),
            ],

            'finance' => [
                ['Month', 'Revenue', 'Cost of sales', 'Gross profit', 'Expenses', 'Net', 'Collected', 'Purchases', 'Tax'],
                $this->reports->financeByMonth($range)->map(fn ($r) => [
                    $r->label,
                    $money($r->revenue),
                    $money($r->cost),
                    $money($r->gross),
                    $money($r->expenses),
                    $money($r->net),
                    $money($r->collected),
                    $money($r->purchases),
                    $money($r->tax),
                ]),
            ],

            'transfers' => [
                ['Reference', 'Date', 'From', 'To', 'Status', 'Quantity', 'Value', 'Dispatched', 'Received'],
                $this->reports->stockTransfers($range)->lazy(500)->map(fn ($r) => [
                    $r->reference,
                    $r->transfer_date?->format('Y-m-d'),
                    trim(($r->shop?->name ?? '').' / '.($r->fromWarehouse?->name ?? ''), ' /'),
                    trim(($r->toShop?->name ?? '').' / '.($r->toWarehouse?->name ?? ''), ' /'),
                    $r->statusLabel(),
                    $qty($r->total_quantity),
                    $money($r->total_value),
                    $r->dispatched_at?->format('Y-m-d H:i'),
                    $r->received_at?->format('Y-m-d H:i'),
                ]),
            ],

            'day-closing' => [
                ['Date', 'Branch', 'Status', 'Opening float', 'Expected', 'Counted', 'Variance', 'Closed by'],
                $this->reports->dayClosings($range)->lazy(500)->map(fn ($r) => [
                    $r->business_date?->format('Y-m-d'),
                    $r->shop?->name,
                    $r->statusLabel(),
                    $money($r->opening_float),
                    $r->expected_cash === null ? '' : $money($r->expected_cash),
                    $r->counted_cash === null ? '' : $money($r->counted_cash),
                    $r->variance === null ? '' : $money($r->variance),
                    $r->closed_by_name,
                ]),
            ],

            'kitchen' => [
                ['Station', 'Tickets', 'Items', 'Quantity', 'Avg wait to accept', 'Avg time to cook', 'Slowest', 'Late', 'Unfinished'],
                $this->reports->kitchenPerformance($range)->map(fn ($r) => [
                    $r->station,
                    $r->tickets,
                    $r->lines_made,
                    $qty($r->quantity),
                    // Minutes to two places, not "4m 12s": a spreadsheet can
                    // average a number and cannot average a string.
                    $r->accept_seconds === null ? '' : number_format($r->accept_seconds / 60, 2),
                    $r->cook_seconds === null ? '' : number_format($r->cook_seconds / 60, 2),
                    $r->worst_seconds === null ? '' : number_format($r->worst_seconds / 60, 2),
                    $r->late,
                    $r->unfinished,
                ]),
            ],

            default => [['Nothing'], collect()],
        };
    }

    /* ----------------------------------------------------------- helpers */

    /**
     * @return array<string, mixed>
     */
    private function meta(string $report): array
    {
        abort_unless(array_key_exists($report, self::REPORTS), 404);

        return self::REPORTS[$report] + ['key' => $report];
    }
}
