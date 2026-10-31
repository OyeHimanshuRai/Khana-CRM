<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyTransaction;
use App\Services\LoyaltyService;
use App\Support\ApiResponse;
use App\Support\CurrentShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * The points programme and who holds what (SRS 15, 21).
 *
 * One screen: the rules at the top, the members below. They belong together
 * because the first question anybody asks after changing an earn rate is
 * "what does that do to the people already on it", and having to navigate to
 * find out is how rates get changed carelessly.
 */
class LoyaltyController extends Controller
{
    private const PAGE_SIZES = [10, 15, 25, 50];

    public function __construct(private readonly LoyaltyService $loyalty) {}

    public function index(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 15;

        /*
         | Members are customers with a non-zero balance, found from the
         | ledger rather than from a column - see LoyaltyService. The
         | aggregate is grouped in SQL so a thousand members is one query
         | rather than a thousand.
         */
        $balances = LoyaltyTransaction::query()
            ->selectRaw('customer_id, SUM(points) as points')
            ->groupBy('customer_id')
            ->havingRaw('SUM(points) <> 0')
            ->pluck('points', 'customer_id');

        $members = Customer::query()
            ->whereIn('id', $balances->keys()->all() ?: [0])
            ->when($request->string('q')->toString(), function (Builder $query, string $term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

                $query->where(fn (Builder $q) => $q
                    ->where('name', 'like', $like)
                    ->orWhere('mobile', 'like', $like)
                    ->orWhere('code', 'like', $like));
            })
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();

        $program = $this->loyalty->program();

        $data = [
            'program' => $program,
            'members' => $members,
            'balances' => $balances,
            'search' => $request->string('q')->toString(),
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'stats' => [
                'members' => $balances->count(),
                'outstanding' => (int) $balances->sum(),
                // What the restaurant would owe if everyone spent today.
                'liability' => $program ? $program->valueOf((int) $balances->sum()) : 0,
                'expiring' => LoyaltyTransaction::query()
                    ->where('type', LoyaltyTransaction::EARN)
                    ->whereNotNull('expires_at')
                    ->whereBetween('expires_at', [now(), now()->addDays(30)])
                    ->sum('points'),
            ],
        ];

        return $request->header('X-Fragment')
            ? view('admin.loyalty._list', $data)
            : view('admin.loyalty.index', $data);
    }

    /** The programme's rules. */
    public function settings(): View
    {
        return view('admin.loyalty._settings', [
            'program' => $this->loyalty->program() ?? new LoyaltyProgram([
                'name' => 'Loyalty',
                'points_per_hundred' => 5,
                'redeem_value' => 1,
                'min_redeem_points' => 100,
                'max_redeem_percent' => 50,
                'min_spend' => 0,
            ]),
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:90'],
            'points_per_hundred' => ['required', 'numeric', 'min:0', 'max:1000'],
            'min_spend' => ['required', 'numeric', 'min:0'],
            'redeem_value' => ['required', 'numeric', 'min:0', 'max:1000'],
            'min_redeem_points' => ['required', 'integer', 'min:0'],
            'max_redeem_percent' => ['required', 'integer', 'min:1', 'max:100'],
            'expiry_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'is_active' => ['boolean'],
        ]);

        $data['is_active'] = $request->boolean('is_active');

        $program = LoyaltyProgram::query()->updateOrCreate(
            ['shop_id' => CurrentShop::idForWrite()],
            $data,
        );

        ActivityLog::record('loyalty.settings', 'Updated the loyalty programme', $program);

        /*
         | Says out loud what the rate now means. "5 points per 100" and
         | "1 rupee a point" are two numbers whose product nobody computes in
         | their head, and the product is what the restaurant is giving away.
         */
        return ApiResponse::success(sprintf(
            'Saved. A %s bill now earns %d points, worth %s — about %s%% back.',
            number_format(1000, 2),
            $program->pointsFor(1000),
            number_format($program->valueOf($program->pointsFor(1000)), 2),
            number_format($program->valueOf($program->pointsFor(1000)) / 10, 1),
        ));
    }

    /** A customer's passbook. */
    public function show(Customer $customer): View
    {
        return view('admin.loyalty._show', [
            'customer' => $customer,
            'balance' => $this->loyalty->balance($customer),
            'statement' => $this->loyalty->statement($customer),
            'program' => $this->loyalty->program(),
        ]);
    }

    /** Put points on or take them off by hand. */
    public function adjust(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'points' => ['required', 'integer', 'min:-100000', 'max:100000'],
            'note' => ['required', 'string', 'max:190'],
        ]);

        try {
            $this->loyalty->adjust($customer, (int) $data['points'], $data['note']);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        ActivityLog::record(
            'loyalty.adjusted',
            sprintf('%s %d points for %s — %s',
                $data['points'] > 0 ? 'Added' : 'Removed',
                abs((int) $data['points']),
                $customer->name,
                $data['note'],
            ),
            $customer,
        );

        return ApiResponse::success(sprintf(
            '%s now has %s points.',
            $customer->name,
            number_format($this->loyalty->balance($customer)),
        ));
    }
}
