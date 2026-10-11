<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\PaymentIntent;
use App\Models\PaymentRefund;
use App\Services\PaymentIntentService;
use App\Services\Payments\GatewayManager;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use RuntimeException;

/**
 * What guests paid from their phones (SRS 4, 11).
 *
 * Three screens, and they are three different jobs:
 *
 *   transactions  what came in, and what state each one is in. Read when a
 *                 guest says they paid and the table still shows unpaid.
 *   refunds       what went back out. Read by whoever answers the phone.
 *   settlement    what the provider says it owes against what this system
 *                 recorded. Read once a day by whoever does the books.
 *
 * Deliberately separate from PaymentController, which is the customer ledger
 * - cash, cheques and what a regular owes. Merging them would put a
 * restaurant's takings and a gateway's clearing account in one list.
 */
class OnlinePaymentController extends Controller
{
    private const PAGE_SIZES = [15, 25, 50, 100];

    public function __construct(
        private readonly PaymentIntentService $payments,
        private readonly GatewayManager $gateways,
    ) {}

    /* ------------------------------------------------------ transactions */

    public function index(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 25);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 25;

        $intents = PaymentIntent::query()
            ->with('payable')
            ->when($request->string('status')->toString(), fn (Builder $q, string $s) => $q->where('status', $s))
            ->when($request->string('q')->toString(), function (Builder $query, string $term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

                $query->where(fn (Builder $q) => $q
                    ->where('reference', 'like', $like)
                    ->orWhere('provider_order_id', 'like', $like)
                    ->orWhere('provider_payment_id', 'like', $like));
            })
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();

        /*
         | How much of each is still refundable, for the whole page in one
         | query. Asking per row would be a query per line on a screen whose
         | entire job is to be scanned quickly.
         */
        $refunded = PaymentRefund::query()
            ->whereIn('payment_intent_id', $intents->pluck('id'))
            ->counted()
            ->selectRaw('payment_intent_id, SUM(amount) as given')
            ->groupBy('payment_intent_id')
            ->pluck('given', 'payment_intent_id');

        $data = [
            'intents' => $intents,
            'refunded' => $refunded,
            'search' => $request->string('q')->toString(),
            'status' => $request->string('status')->toString(),
            'statuses' => PaymentIntent::STATUSES,
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'canRefund' => $request->user()?->can('finance.online_payments.refund') && $this->gateways->isLive(),
            'live' => $this->gateways->isLive(),
            'stats' => $this->stats(),
        ];

        return $request->header('X-Fragment')
            ? view('admin.online-payments._list', $data)
            : view('admin.online-payments.index', $data);
    }

    /** @return array<string, mixed> */
    private function stats(): array
    {
        $today = PaymentIntent::query()->whereDate('created_at', today());

        return [
            'paid_today' => (float) (clone $today)->where('status', PaymentIntent::PAID)->sum('amount'),
            'count_today' => (clone $today)->where('status', PaymentIntent::PAID)->count(),
            /*
             | Started and never finished. The number worth watching: a
             | handful is normal (people change their mind at the page), a
             | lot means the checkout is broken.
             */
            'abandoned_today' => (clone $today)->where('status', PaymentIntent::PENDING)->count(),
            'refunded_today' => (float) PaymentRefund::query()
                ->whereDate('created_at', today())->counted()->sum('amount'),
        ];
    }

    /* ---------------------------------------------------------- refunds */

    public function refunds(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 25);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 25;

        $refunds = PaymentRefund::query()
            ->with(['intent:id,reference,amount', 'user:id,name'])
            ->when($request->boolean('failed'), fn (Builder $q) => $q->failed())
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();

        $data = [
            'refunds' => $refunds,
            'failed' => $request->boolean('failed'),
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'failedCount' => PaymentRefund::query()->failed()->count(),
        ];

        return $request->header('X-Fragment')
            ? view('admin.online-payments._refunds', $data)
            : view('admin.online-payments.refunds', $data);
    }

    /* ------------------------------------------------------- settlement */

    /**
     * What the provider owes, day by day (SRS 11).
     *
     * Deliberately built from this system's own records rather than pulled
     * from the provider's API. The whole point of a reconciliation is to have
     * two independent accounts to compare; a report that fetched the
     * provider's figures and printed them would agree with the provider
     * always, including on the day they were wrong.
     *
     * So this is one side of it. The other is the provider's settlement
     * statement, and the job is to read them next to each other.
     */
    public function settlements(Request $request): View
    {
        $from = $this->date($request->string('from')->toString(), now()->subDays(29));
        $to = $this->date($request->string('to')->toString(), now());

        $paid = PaymentIntent::query()
            ->where('status', '!=', PaymentIntent::PENDING)
            ->whereBetween('paid_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->selectRaw('DATE(paid_at) as day, COUNT(*) as orders, SUM(amount) as gross')
            ->groupBy('day')
            ->pluck('gross', 'day');

        $counts = PaymentIntent::query()
            ->where('status', '!=', PaymentIntent::PENDING)
            ->whereBetween('paid_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->selectRaw('DATE(paid_at) as day, COUNT(*) as orders')
            ->groupBy('day')
            ->pluck('orders', 'day');

        $back = PaymentRefund::query()
            ->counted()
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->selectRaw('DATE(created_at) as day, SUM(amount) as given')
            ->groupBy('day')
            ->pluck('given', 'day');

        $days = collect($paid->keys())
            ->merge($back->keys())
            ->unique()
            ->sortDesc()
            ->values()
            ->map(fn ($day) => [
                'day' => $day,
                'orders' => (int) ($counts[$day] ?? 0),
                'gross' => (float) ($paid[$day] ?? 0),
                'refunded' => (float) ($back[$day] ?? 0),
                'net' => round((float) ($paid[$day] ?? 0) - (float) ($back[$day] ?? 0), 2),
            ]);

        return view('admin.online-payments.settlements', [
            'days' => $days,
            'from' => $from,
            'to' => $to,
            'totals' => [
                'gross' => $days->sum('gross'),
                'refunded' => $days->sum('refunded'),
                'net' => $days->sum('net'),
                'orders' => $days->sum('orders'),
            ],
        ]);
    }

    private function date(string $raw, Carbon $fallback): Carbon
    {
        if ($raw === '') {
            return $fallback;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable $e) {
            return $fallback;
        }
    }

    /* ------------------------------------------------------- the action */

    public function refundForm(PaymentIntent $intent): View
    {
        return view('admin.online-payments._refund', [
            'intent' => $intent->load('payable'),
            'available' => $this->payments->refundableAmount($intent),
            'history' => PaymentRefund::query()
                ->where('payment_intent_id', $intent->id)
                ->latest('id')
                ->get(),
            'live' => $this->gateways->isLive(),
        ]);
    }

    public function refund(Request $request, PaymentIntent $intent): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        try {
            $refund = $this->payments->refund($intent, (float) $data['amount'], $data['reason']);
        } catch (RuntimeException $e) {
            // The service's refusals are written for whoever pressed the
            // button, so they are passed through unchanged.
            return ApiResponse::error($e->getMessage());
        }

        ActivityLog::record(
            'payment.refunded',
            sprintf('Refunded %s %s against %s — %s',
                $refund->currency,
                number_format((float) $refund->amount, 2),
                $intent->reference,
                $data['reason'],
            ),
            $refund,
        );

        return ApiResponse::success(sprintf(
            '%s sent back. It usually reaches the guest in three to five working days.',
            number_format((float) $refund->amount, 2),
        ));
    }
}
