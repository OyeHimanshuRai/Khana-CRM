<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\GoodsReceipt;
use App\Support\CurrentShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The supplier's bills - a register over posted goods receipts, not a
 * document of its own.
 *
 * GoodsReceipt already is the purchase invoice: see its docblock -
 * "What arrived, and what the supplier billed for it. One document for
 * both." Raising, editing or cancelling a bill already happens through
 * Admin\GoodsReceiptController; recreating that here under a different name
 * would give every bill two places that could disagree about it.
 *
 * What this screen adds is a view of the same rows through the bill rather
 * than the delivery: sorted and filterable by what is owed and by when it
 * is due, the way accounts payable actually works the register, rather
 * than by warehouse and batch the way receiving does. `purchasing.bills.*`
 * only wires `view`/`export`/`print` for that reason - the
 * create/edit/delete/approve/cancel actions the config declares belong to
 * the receipt, not to this read of it.
 */
class PurchaseInvoiceController extends Controller
{
    private const PAGE_SIZES = [10, 15, 25, 50];

    public function index(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 15;

        $bills = $this->filtered($request)
            ->with(['supplier:id,name,company', 'shop:id,name'])
            ->paginate($perPage)
            ->withQueryString();

        $data = [
            'bills' => $bills,
            'search' => $request->string('q')->toString(),
            'settlement' => $request->string('settlement')->toString(),
            'from' => $request->string('from')->toString(),
            'to' => $request->string('to')->toString(),
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'showsShop' => CurrentShop::id() === null,
            'stats' => [
                'count' => $this->billed()->count(),
                'billed' => (float) $this->billed()->sum('grand_total'),
                'outstanding' => (float) $this->billed()->unpaid()->sum('due_total'),
                'overdue' => (float) $this->billed()->unpaid()
                    ->whereNotNull('due_date')->whereDate('due_date', '<', today())
                    ->sum('due_total'),
            ],
        ];

        return $request->header('X-Fragment')
            ? view('admin.purchase-invoices._list', $data)
            : view('admin.purchase-invoices.index', $data);
    }

    private function billed(): Builder
    {
        return GoodsReceipt::query()->counted();
    }

    private function filtered(Request $request): Builder
    {
        return $this->billed()
            ->search($request->string('q')->toString())
            ->between($request->string('from')->toString(), $request->string('to')->toString())
            ->when($request->string('settlement')->toString(), function (Builder $query, string $filter) {
                match ($filter) {
                    'unpaid' => $query->unpaid(),
                    'overdue' => $query->unpaid()->whereNotNull('due_date')->whereDate('due_date', '<', today()),
                    'paid' => $query->where('due_total', '<=', 0),
                    default => null,
                };
            })
            ->orderByDesc('received_on')
            ->orderByDesc('id');
    }

    public function print(GoodsReceipt $receipt): View
    {
        return view('admin.purchase-invoices.print', [
            'receipt' => $receipt->load(['supplier', 'shop']),
            'items' => $receipt->items()->with('product:id,name,sku')->get(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filename = 'purchase-invoices-'.now()->format('Y-m-d-His').'.csv';

        $columns = [
            'Bill number', 'Bill date', 'Due date', 'Receipt', 'Shop', 'Supplier',
            'Total', 'Paid', 'Due', 'Status',
        ];

        $query = $this->filtered($request)->with(['supplier', 'shop']);

        ActivityLog::record('purchase_invoice.exported', 'Exported the purchase invoice register');

        return response()->streamDownload(function () use ($query, $columns) {
            $handle = fopen('php://output', 'wb');

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $columns);

            $query->chunkById(300, function ($chunk) use ($handle) {
                foreach ($chunk as $receipt) {
                    fputcsv($handle, [
                        $receipt->bill_number ?: '—',
                        $receipt->bill_date?->format('Y-m-d'),
                        $receipt->due_date?->format('Y-m-d'),
                        $receipt->reference,
                        $receipt->shop?->name,
                        $receipt->supplier?->displayName(),
                        number_format((float) $receipt->grand_total, 2, '.', ''),
                        number_format((float) $receipt->paid_total, 2, '.', ''),
                        number_format((float) $receipt->due_total, 2, '.', ''),
                        (float) $receipt->due_total <= 0 ? 'Paid' : ($receipt->isOverdue() ? 'Overdue' : 'Outstanding'),
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
