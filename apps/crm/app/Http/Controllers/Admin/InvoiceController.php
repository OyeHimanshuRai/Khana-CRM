<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Shop;
use App\Services\InvoiceService;
use App\Support\ApiResponse;
use App\Support\CurrentShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sales invoices, after the sale.
 *
 * Read, print, cancel. Raising one belongs to PosController, because that is
 * one screen with one flow; this is the register the shop looks things up
 * in afterwards.
 *
 * There is deliberately no edit. An invoice that has been handed to a
 * customer and taken stock off a shelf is a record of something that
 * happened - correcting it means cancelling and re-raising, which is what
 * the SRS asks for and what leaves an audit trail worth having.
 */
class InvoiceController extends Controller
{
    private const PAGE_SIZES = [10, 15, 25, 50, 100];

    public function __construct(private readonly InvoiceService $invoices) {}

    /* -------------------------------------------------------------- list */

    public function index(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 15;

        $invoices = $this->filtered($request)
            ->with(['customer:id,name,mobile', 'shop:id,name,code'])
            ->withCount('items')
            ->paginate($perPage)
            ->withQueryString();

        $data = [
            'invoices' => $invoices,
            'search' => $request->string('q')->toString(),
            'status' => $request->string('status')->toString(),
            'channel' => $request->string('channel')->toString(),
            'settlement' => $request->string('settlement')->toString(),
            'from' => $request->string('from')->toString(),
            'to' => $request->string('to')->toString(),
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'statuses' => Invoice::STATUSES,
            'channels' => Invoice::CHANNELS,
            'showsShop' => CurrentShop::id() === null,
            'stats' => $this->stats($request),
        ];

        return $request->header('X-Fragment')
            ? view('admin.invoices._list', $data)
            : view('admin.invoices.index', $data);
    }

    /**
     * Headline figures for the filtered range.
     *
     * Cancelled invoices are excluded from every one of them - a sales
     * figure that counts voided bills is the fastest way to lose trust in a
     * report.
     *
     * @return array<string, mixed>
     */
    private function stats(Request $request): array
    {
        $base = fn () => $this->filtered($request)->counted();

        return [
            'count' => $base()->count(),
            'sales' => (float) $base()->sum('grand_total'),
            'collected' => (float) $base()->sum('paid_total'),
            'outstanding' => (float) $base()->sum('due_total'),
        ];
    }

    private function filtered(Request $request): Builder
    {
        return Invoice::query()
            ->search($request->string('q')->toString())
            ->ofStatus($request->string('status')->toString())
            ->ofChannel($request->string('channel')->toString())
            ->between($request->string('from')->toString(), $request->string('to')->toString())
            ->when($request->string('settlement')->toString(), function (Builder $query, string $filter) {
                match ($filter) {
                    'outstanding' => $query->outstanding(),
                    'overdue' => $query->overdue(),
                    'credit' => $query->counted()->where('is_credit', true),
                    'settled' => $query->counted()->where('due_total', '<=', 0),
                    default => null,
                };
            })
            ->orderByDesc('invoiced_at')
            ->orderByDesc('id');
    }

    /* ------------------------------------------------------------ detail */

    public function show(Invoice $invoice): View
    {
        return view('admin.invoices.show', [
            'invoice' => $invoice->load([
                'items.product:id,name,sku',
                'customer',
                'warehouse:id,name',
                'shop',
                'creator:id,name',
            ]),
            'payments' => $invoice->payments()->get(),
            'shop' => Shop::withTrashed()->find($invoice->shop_id),
        ]);
    }

    /**
     * The printable document.
     *
     * Its own bare layout rather than the admin shell: what comes out of the
     * printer should be the invoice, not a screenshot of the application.
     * Width is driven by the shop's channel - a counter receipt is a 80mm
     * roll, a manual invoice is A4.
     */
    public function print(Request $request, Invoice $invoice): View
    {
        $format = $request->string('format')->toString()
            ?: ($invoice->channel === Invoice::POS ? 'receipt' : 'a4');

        ActivityLog::record(
            'invoice.printed',
            "Printed invoice {$invoice->number}",
            $invoice,
            ['format' => $format],
        );

        return view('admin.invoices.print', [
            'invoice' => $invoice->load(['items', 'customer', 'shop']),
            'payments' => $invoice->payments()->where('status', '!=', Payment::CANCELLED)->get(),
            'shop' => Shop::withTrashed()->find($invoice->shop_id),
            'format' => $format === 'receipt' ? 'receipt' : 'a4',
            // The HSN summary a GST invoice has to carry.
            'hsnSummary' => $this->hsnSummary($invoice),
        ]);
    }

    /**
     * Tax grouped by HSN code, which is what a GST invoice must show.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function hsnSummary(Invoice $invoice)
    {
        return $invoice->items
            ->groupBy(fn ($item) => ($item->hsn_code ?: '—').'|'.$item->tax_rate)
            ->map(fn ($group) => [
                'hsn' => $group->first()->hsn_code ?: '—',
                'rate' => (float) $group->first()->tax_rate,
                'taxable' => (float) $group->sum('taxable_value'),
                'cgst' => (float) $group->sum('cgst_amount'),
                'sgst' => (float) $group->sum('sgst_amount'),
                'igst' => (float) $group->sum('igst_amount'),
                'cess' => (float) $group->sum('cess_amount'),
            ])
            ->values();
    }

    /* ------------------------------------------------------------ cancel */

    /**
     * Void an invoice.
     *
     * The reason is mandatory and ends up on the document, in the activity
     * log and on the customer's statement. "Why was this cancelled" is the
     * first question anyone asks about a voided bill.
     */
    public function cancel(Request $request, Invoice $invoice): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:250'],
        ], [
            'reason.required' => 'Say why this invoice is being cancelled.',
            'reason.min' => 'Give a reason someone can understand later.',
        ]);

        try {
            $this->invoices->cancel($invoice, $data['reason']);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        return ApiResponse::success(
            "Invoice {$invoice->number} cancelled. The stock is back and the account is reversed.",
            [],
            route('admin.invoices.show', $invoice),
        );
    }

    /** The cancel form, in a modal. */
    public function cancelForm(Invoice $invoice): View
    {
        return view('admin.invoices._cancel', ['invoice' => $invoice]);
    }

    /* ------------------------------------------------------------ export */

    public function export(Request $request): StreamedResponse
    {
        $filename = 'invoices-'.now()->format('Y-m-d-His').'.csv';

        $columns = [
            'Number', 'Date', 'Shop', 'Channel', 'Customer', 'Mobile', 'GSTIN',
            'Sub-total', 'Discount', 'CGST', 'SGST', 'IGST', 'Cess', 'Round off',
            'Total', 'Paid', 'Due', 'Due date', 'Status',
        ];

        $query = $this->filtered($request)->with('shop');

        ActivityLog::record('invoice.exported', 'Exported the invoice register');

        return response()->streamDownload(function () use ($query, $columns) {
            $handle = fopen('php://output', 'wb');

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $columns);

            $query->chunkById(300, function ($chunk) use ($handle) {
                foreach ($chunk as $invoice) {
                    fputcsv($handle, [
                        $invoice->number,
                        $invoice->invoiced_at?->format('Y-m-d H:i'),
                        $invoice->shop?->name,
                        $invoice->channelLabel(),
                        $invoice->billedTo(),
                        $invoice->customer_mobile,
                        $invoice->customer_gstin,
                        number_format((float) $invoice->subtotal, 2, '.', ''),
                        number_format((float) $invoice->line_discount_total + (float) $invoice->invoice_discount, 2, '.', ''),
                        number_format((float) $invoice->cgst_total, 2, '.', ''),
                        number_format((float) $invoice->sgst_total, 2, '.', ''),
                        number_format((float) $invoice->igst_total, 2, '.', ''),
                        number_format((float) $invoice->cess_total, 2, '.', ''),
                        number_format((float) $invoice->round_off, 2, '.', ''),
                        number_format((float) $invoice->grand_total, 2, '.', ''),
                        number_format((float) $invoice->paid_total, 2, '.', ''),
                        number_format((float) $invoice->due_total, 2, '.', ''),
                        $invoice->due_date?->format('Y-m-d'),
                        $invoice->statusLabel(),
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
