<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\Invoice;
use App\Services\LedgerService;
use App\Support\ApiResponse;
use App\Support\CurrentShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The customer account: statements, dues and write-offs.
 *
 * Three screens over the same data, because three different people ask
 * three different questions of it:
 *
 *   ledger      "what has this customer done" - one account, in order
 *   dues        "who owes us" - every account, worst first, aged
 *   statement   the printable version of the first
 */
class CustomerLedgerController extends Controller
{
    private const PAGE_SIZES = [25, 50, 100, 200];

    /**
     * The ageing buckets a collections clerk actually works in.
     *
     * `from` and `to` are days overdue, inclusive, measured against the
     * invoice's due date - so a customer given 30 days is not late on day
     * one. A null end means "no bound in that direction".
     */
    private const BUCKETS = [
        ['label' => 'Not yet due', 'from' => null, 'to' => -1],
        ['label' => 'Due now – 30 days', 'from' => 0, 'to' => 30],
        ['label' => '31–60 days', 'from' => 31, 'to' => 60],
        ['label' => '61–90 days', 'from' => 61, 'to' => 90],
        ['label' => 'Over 90 days', 'from' => 91, 'to' => null],
    ];

    public function __construct(private readonly LedgerService $ledger) {}

    /* ------------------------------------------------------------ ledger */

    /**
     * One customer's account, in order.
     *
     * Without a customer the screen is a picker rather than an error: the
     * question "whose ledger" is the first thing it has to ask.
     */
    public function index(Request $request): View
    {
        $customer = $request->integer('customer')
            ? Customer::find($request->integer('customer'))
            : null;

        $perPage = (int) $request->integer('per_page', 50);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 50;

        $entries = $customer
            ? CustomerLedger::query()
                ->where('customer_id', $customer->id)
                ->ofType($request->string('type')->toString())
                ->between($request->string('from')->toString(), $request->string('to')->toString())
                ->statementOrder()
                ->paginate($perPage)
                ->withQueryString()
            : null;

        $data = [
            'customer' => $customer,
            'entries' => $entries,
            'type' => $request->string('type')->toString(),
            'from' => $request->string('from')->toString(),
            'to' => $request->string('to')->toString(),
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'types' => CustomerLedger::TYPES,
            'invoices' => $customer
                ? Invoice::query()
                    ->where('customer_id', $customer->id)
                    ->outstanding()
                    ->orderBy('due_date')
                    ->get()
                : collect(),
        ];

        return $request->header('X-Fragment') && $customer
            ? view('admin.ledger._entries', $data)
            : view('admin.ledger.index', $data);
    }

    /**
     * The printable statement.
     *
     * Its own bare page, like the invoice - what gets posted to a customer
     * should be the statement, not a screenshot.
     */
    public function statement(Request $request, Customer $customer): View
    {
        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();

        $entries = CustomerLedger::query()
            ->where('customer_id', $customer->id)
            ->between($from, $to)
            ->statementOrder()
            ->get();

        /*
         | The balance the account stood at before the first entry shown.
         | Without it a date-filtered statement reads as though the customer
         | started from zero, which is how disputes begin.
         */
        $opening = $from
            ? (float) CustomerLedger::query()
                ->where('customer_id', $customer->id)
                ->whereDate('entered_at', '<', $from)
                ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as total')
                ->value('total')
            : 0.0;

        ActivityLog::record(
            'customer.statement_printed',
            "Printed a statement for {$customer->name}",
            $customer,
        );

        return view('admin.ledger.statement', [
            'customer' => $customer->load('shop'),
            'entries' => $entries,
            'opening' => $opening,
            'from' => $from,
            'to' => $to,
        ]);
    }

    /* --------------------------------------------------------------- dues */

    /**
     * Who owes what, aged.
     *
     * Aged on the invoice's due date rather than its issue date, because
     * that is what a credit term means: nothing is late until the term the
     * shop granted has run out.
     */
    public function dues(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 25);
        $perPage = in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 25;

        $customers = $this->duesQuery($request)
            ->paginate($perPage)
            ->withQueryString();

        return $request->header('X-Fragment')
            ? view('admin.dues._list', [
                'customers' => $customers,
                'perPage' => $perPage,
                'buckets' => self::BUCKETS,
            ])
            : view('admin.dues.index', [
                'customers' => $customers,
                'search' => $request->string('q')->toString(),
                'bucket' => $request->string('bucket')->toString(),
                'perPage' => $perPage,
                'pageSizes' => [10, 25, 50, 100],
                'buckets' => self::BUCKETS,
                'ageing' => $this->ageing(),
                'stats' => [
                    'customers' => Customer::query()->withDues()->count(),
                    'outstanding' => (float) Customer::query()->withDues()->sum('balance'),
                    'overdue' => (float) Invoice::query()->overdue()->sum('due_total'),
                    'due_today' => (float) Invoice::query()
                        ->outstanding()
                        ->whereDate('due_date', today())
                        ->sum('due_total'),
                ],
            ]);
    }

    private function duesQuery(Request $request): Builder
    {
        return Customer::query()
            ->withDues()
            ->search($request->string('q')->toString())
            ->when($request->string('bucket')->toString(), function (Builder $query, string $bucket) {
                $definition = collect(self::BUCKETS)->firstWhere('label', $bucket);

                if (! $definition) {
                    return;
                }

                /*
                 | A customer belongs to a bucket if any of their unpaid
                 | invoices does. Someone 100 days late on one bill and
                 | current on another is, correctly, in both.
                 |
                 | Expressed as date comparisons rather than DATEDIFF so the
                 | same query runs on MySQL and on SQLite, and so the index
                 | on due_date is usable.
                 */
                $query->whereExists(function ($sub) use ($definition) {
                    $sub->selectRaw('1')
                        ->from('invoices')
                        ->whereColumn('invoices.customer_id', 'customers.id')
                        ->where('invoices.due_total', '>', 0)
                        ->whereNotIn('invoices.status', [Invoice::DRAFT, Invoice::CANCELLED])
                        ->whereNotNull('invoices.due_date');

                    // days overdue >= from  ⟺  due_date <= today - from
                    if ($definition['from'] !== null) {
                        $sub->whereDate('invoices.due_date', '<=', today()->subDays($definition['from']));
                    }

                    // days overdue <= to  ⟺  due_date >= today - to
                    if ($definition['to'] !== null) {
                        $sub->whereDate('invoices.due_date', '>=', today()->subDays($definition['to']));
                    }
                });
            })
            ->orderByDesc('balance');
    }

    /**
     * Total outstanding per ageing bucket.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function ageing()
    {
        return collect(self::BUCKETS)->map(function (array $bucket) {
            $query = Invoice::query()->outstanding()->whereNotNull('due_date');

            if ($bucket['from'] !== null) {
                $query->whereDate('due_date', '<=', today()->subDays($bucket['from']));
            }

            if ($bucket['to'] !== null) {
                $query->whereDate('due_date', '>=', today()->subDays($bucket['to']));
            }

            return [
                'label' => $bucket['label'],
                'total' => (float) $query->sum('due_total'),
                'count' => $query->count(),
            ];
        });
    }

    /* ---------------------------------------------------------- write-off */

    public function writeOffForm(Customer $customer): View
    {
        return view('admin.dues._write-off', ['customer' => $customer]);
    }

    /**
     * Forgive an outstanding balance.
     *
     * Its own permission and its own ledger type, because it is the one
     * operation that makes money disappear without anyone paying it - and
     * an audit will look for it by name.
     */
    public function writeOff(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999'],
            'reason' => ['required', 'string', 'min:5', 'max:250'],
        ], [
            'reason.required' => 'A write-off needs a reason. It is the entry an audit looks for.',
            'reason.min' => 'Give a reason someone can understand in five years.',
            'amount.gt' => 'A write-off has to be for more than zero.',
        ]);

        $amount = (float) $data['amount'];
        $balance = (float) $customer->balance;

        if ($amount > $balance + 0.004) {
            return ApiResponse::error(sprintf(
                '%s owes ₹%s. Writing off more than that would leave them in credit.',
                $customer->name,
                number_format($balance, 2),
            ));
        }

        $this->ledger->writeOff($customer, $amount, $data['reason']);

        ActivityLog::record(
            'customer.written_off',
            sprintf('Wrote off ₹%s for %s: %s',
                number_format($amount, 2), $customer->name, $data['reason']),
            $customer,
            ['amount' => $amount, 'balance_before' => $balance],
        );

        return ApiResponse::success(sprintf(
            'Wrote off ₹%s. %s now owes ₹%s.',
            number_format($amount, 2),
            $customer->name,
            number_format((float) $customer->fresh()->balance, 2),
        ));
    }

    /* ------------------------------------------------------------ export */

    public function export(Request $request): StreamedResponse
    {
        $filename = 'outstanding-'.now()->format('Y-m-d-His').'.csv';

        $columns = [
            'Code', 'Customer', 'Mobile', 'Village', 'Credit limit',
            'Balance', 'Oldest due date', 'Days overdue',
        ];

        $query = $this->duesQuery($request);

        ActivityLog::record('dues.exported', 'Exported the outstanding report');

        return response()->streamDownload(function () use ($query, $columns) {
            $handle = fopen('php://output', 'wb');

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $columns);

            $query->chunkById(300, function ($chunk) use ($handle) {
                foreach ($chunk as $customer) {
                    $oldest = Invoice::query()
                        ->where('customer_id', $customer->id)
                        ->outstanding()
                        ->whereNotNull('due_date')
                        ->orderBy('due_date')
                        ->first();

                    fputcsv($handle, [
                        $customer->code,
                        $customer->name,
                        $customer->mobile,
                        $customer->village,
                        number_format((float) $customer->credit_limit, 2, '.', ''),
                        number_format((float) $customer->balance, 2, '.', ''),
                        $oldest?->due_date?->format('Y-m-d'),
                        $oldest && $oldest->isOverdue() ? $oldest->daysOverdue() : 0,
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
