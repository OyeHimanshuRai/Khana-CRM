<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\PaymentService;
use App\Support\ApiResponse;
use App\Support\CurrentShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Collections and the payment register.
 *
 * There is no update route, on purpose. A recorded payment is a statement
 * about money that changed hands; correcting it means reversing it, which
 * leaves both entries visible. That is what the SRS's separate "payment
 * adjustment" right is protecting.
 */
class PaymentController extends Controller
{
    private const PAGE_SIZES = [10, 15, 25, 50, 100];

    public function __construct(private readonly PaymentService $payments) {}

    /* -------------------------------------------------------------- list */

    public function index(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 15;

        $payments = $this->filtered($request)
            ->with(['party', 'reference', 'shop:id,name,code'])
            ->paginate($perPage)
            ->withQueryString();

        $data = [
            'payments' => $payments,
            'search' => $request->string('q')->toString(),
            'method' => $request->string('method')->toString(),
            'direction' => $request->string('direction')->toString(),
            'status' => $request->string('status')->toString(),
            'from' => $request->string('from')->toString(),
            'to' => $request->string('to')->toString(),
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'methods' => Payment::METHODS,
            'statuses' => Payment::STATUSES,
            'showsShop' => CurrentShop::id() === null,
            'stats' => $this->stats($request),
            'byMethod' => $this->byMethod($request),
        ];

        return $request->header('X-Fragment')
            ? view('admin.payments._list', $data)
            : view('admin.payments.index', $data);
    }

    /**
     * @return array<string, mixed>
     */
    private function stats(Request $request): array
    {
        $base = fn () => $this->filtered($request)->effective();

        return [
            'in' => (float) $base()->incoming()->sum('amount'),
            'out' => (float) $base()->outgoing()->sum('amount'),
            'pending' => (float) $this->filtered($request)->where('status', Payment::PENDING)->sum('amount'),
            'bounced' => (float) $this->filtered($request)->where('status', Payment::BOUNCED)->sum('amount'),
        ];
    }

    /**
     * Collections split by method - the SRS's payment-method report, in
     * miniature, on the screen where people already are.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function byMethod(Request $request)
    {
        // reorder() first: filtered() sorts by date, which is meaningless
        // once the rows are grouped by method.
        return $this->filtered($request)
            ->effective()
            ->incoming()
            ->reorder()
            ->selectRaw('method, COUNT(*) as entries, SUM(amount) as total')
            ->groupBy('method')
            ->orderByDesc('total')
            ->get();
    }

    private function filtered(Request $request): Builder
    {
        return Payment::query()
            ->search($request->string('q')->toString())
            ->ofMethod($request->string('method')->toString())
            ->between($request->string('from')->toString(), $request->string('to')->toString())
            ->when($request->string('direction')->toString(), fn (Builder $q, string $d) => $q->where('direction', $d))
            ->when($request->string('status')->toString(), fn (Builder $q, string $s) => $q->where('status', $s))
            ->orderByDesc('paid_at')
            ->orderByDesc('id');
    }

    /* ------------------------------------------------------ modal screens */

    /**
     * The collection form.
     *
     * Opened either from a customer (settle their oldest debts) or from an
     * invoice (settle this one), because both are things people do and each
     * wants a different default.
     */
    public function create(Request $request): View
    {
        $customer = $request->integer('customer')
            ? Customer::find($request->integer('customer'))
            : null;

        $invoice = $request->integer('invoice')
            ? Invoice::find($request->integer('invoice'))
            : null;

        if ($invoice && ! $customer) {
            $customer = $invoice->customer;
        }

        return view('admin.payments._form', [
            'customer' => $customer,
            'invoice' => $invoice,
            'methods' => Payment::METHODS,
            'outstanding' => $customer
                ? Invoice::query()
                    ->where('customer_id', $customer->id)
                    ->outstanding()
                    ->orderBy('invoiced_at')
                    ->get()
                : collect(),
        ]);
    }

    public function show(Payment $payment): View
    {
        return view('admin.payments._show', [
            'payment' => $payment->load(['party', 'reference', 'shop', 'reverses']),
        ]);
    }

    /* ------------------------------------------------------------ writes */

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $customer = Customer::findOrFail($data['customer_id']);

        // Explicit per-invoice amounts when the form sent them, oldest-first
        // when it did not.
        $allocations = collect($data['allocations'] ?? [])
            ->filter(fn ($amount) => (float) $amount > 0)
            ->mapWithKeys(fn ($amount, $invoiceId) => [(int) $invoiceId => (float) $amount])
            ->all();

        try {
            $payment = $this->payments->collect(
                $customer,
                (float) $data['amount'],
                $data['method'],
                [
                    'paid_at' => $data['paid_at'] ?? now(),
                    'transaction_ref' => $data['transaction_ref'] ?? null,
                    'bank_name' => $data['bank_name'] ?? null,
                    'cheque_date' => $data['cheque_date'] ?? null,
                    'notes' => $data['notes'] ?? null,
                ],
                $allocations ?: null,
            );
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        return ApiResponse::success(
            $payment->isEffective()
                ? sprintf('%s · ₹%s received from %s. Balance ₹%s.',
                    $payment->number,
                    number_format((float) $payment->amount, 2),
                    $customer->name,
                    number_format((float) $customer->fresh()->balance, 2),
                )
                : sprintf('%s · %s of ₹%s recorded, pending clearance.',
                    $payment->number,
                    $payment->methodLabel(),
                    number_format((float) $payment->amount, 2),
                ),
            ['id' => $payment->id, 'number' => $payment->number],
        );
    }

    /* --------------------------------------------------------- lifecycle */

    public function clear(Payment $payment): JsonResponse
    {
        try {
            $this->payments->clear($payment);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        return ApiResponse::success("Payment {$payment->number} cleared.");
    }

    public function bounce(Request $request, Payment $payment): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:250'],
        ], [
            'reason.required' => 'Say why the payment bounced — the customer will ask.',
        ]);

        try {
            $this->payments->bounce($payment, $data['reason']);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        return ApiResponse::success(
            "Payment {$payment->number} marked as bounced. The debt is back on the account."
        );
    }

    /**
     * Reverse a payment.
     *
     * Gated on finance.payments.adjust rather than edit: rewriting what was
     * collected changes a customer's balance, and the SRS wants that right
     * granted deliberately and audited.
     */
    public function reverse(Request $request, Payment $payment): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:250'],
        ], [
            'reason.required' => 'Reversing a payment needs a reason. It goes on the customer\'s statement.',
        ]);

        try {
            $reversal = $this->payments->reverse($payment, $data['reason']);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        return ApiResponse::success(
            "Payment {$payment->number} reversed by {$reversal->number}. Both stay on the record."
        );
    }

    /** The bounce / reverse confirmation, in a modal. */
    public function actionForm(Request $request, Payment $payment): View
    {
        $action = $request->string('action')->toString() === 'bounce' ? 'bounce' : 'reverse';

        return view('admin.payments._action', [
            'payment' => $payment,
            'action' => $action,
        ]);
    }

    /* ------------------------------------------------------------ export */

    public function export(Request $request): StreamedResponse
    {
        $filename = 'payments-'.now()->format('Y-m-d-His').'.csv';

        $columns = [
            'Number', 'Date', 'Shop', 'Direction', 'Method', 'Party',
            'Against', 'Amount', 'Reference', 'Status', 'Recorded by',
        ];

        $query = $this->filtered($request)->with(['shop', 'reference']);

        ActivityLog::record('payment.exported', 'Exported the payment register');

        return response()->streamDownload(function () use ($query, $columns) {
            $handle = fopen('php://output', 'wb');

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $columns);

            $query->chunkById(500, function ($chunk) use ($handle) {
                foreach ($chunk as $payment) {
                    fputcsv($handle, [
                        $payment->number,
                        $payment->paid_at?->format('Y-m-d H:i'),
                        $payment->shop?->name,
                        $payment->direction === Payment::IN ? 'Received' : 'Paid',
                        $payment->methodLabel(),
                        $payment->party_name,
                        $payment->reference?->number ?? '',
                        number_format((float) $payment->amount, 2, '.', ''),
                        $payment->transaction_ref,
                        $payment->statusLabel(),
                        $payment->created_by_name,
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /* ------------------------------------------------------- validation */

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $shopId = CurrentShop::id();

        return $request->validate([
            'customer_id' => [
                'required', 'integer',
                Rule::exists('customers', 'id')
                    ->where('shop_id', $shopId)
                    ->whereNull('deleted_at'),
            ],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999'],
            'method' => ['required', Rule::in(array_keys(Payment::METHODS))],
            'paid_at' => ['nullable', 'date', 'before_or_equal:now'],
            'transaction_ref' => ['nullable', 'string', 'max:120'],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'cheque_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],

            'allocations' => ['nullable', 'array'],
            'allocations.*' => ['nullable', 'numeric', 'min:0'],
        ], [
            'customer_id.exists' => 'That customer is not available in this shop.',
            'amount.gt' => 'A payment has to be for more than zero.',
            'paid_at.before_or_equal' => 'A payment cannot be dated in the future.',
        ]);
    }
}
