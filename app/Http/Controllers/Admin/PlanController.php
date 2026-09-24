<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\ApiResponse;
use App\Support\Modules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The platform's price list (SRS 2, 21).
 *
 * Super Admin's screen. A restaurant never opens it - it reads the one plan
 * it is on through SubscriptionController::mine - so nothing here is scoped
 * by shop or tenant, and nothing here needs to be.
 *
 * ---------------------------------------------------------------------------
 * A plan in use is not deletable
 * ---------------------------------------------------------------------------
 *
 * The foreign key says so and so does this controller, with a better error
 * message. Withdrawing a plan from sale is what is almost always meant, and
 * that is the `is_active` toggle: existing subscribers keep it, nobody new
 * can be put on it. Deleting is reserved for a plan that was created by
 * mistake and sold to nobody.
 */
class PlanController extends Controller
{
    private const PAGE_SIZES = [10, 15, 25, 50];

    /* -------------------------------------------------------------- list */

    public function index(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 15;

        $plans = $this->filtered($request)
            ->withCount(['subscriptions'])
            ->paginate($perPage)
            ->withQueryString();

        /*
         | Subscriber counts, but only the ones that currently matter.
         |
         | `subscriptions_count` above counts history - every row a plan
         | change ever left behind - and showing that as "subscribers" would
         | say a withdrawn plan has forty customers when it has none. So the
         | live figure is counted separately, from the newest row per tenant.
         */
        $live = $this->liveSubscriberCounts();

        $data = [
            'plans' => $plans,
            'live' => $live,
            // The list names the modules a plan grants, so it needs the
            // catalogue to turn keys into labels.
            'modules' => Modules::catalogue(),
            'search' => $request->string('q')->toString(),
            'status' => $request->string('status')->toString(),
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'stats' => [
                'total' => Plan::query()->count(),
                'active' => Plan::query()->where('is_active', true)->count(),
                'subscribers' => array_sum($live),
            ],
        ];

        return $request->header('X-Fragment')
            ? view('admin.plans._list', $data)
            : view('admin.plans.index', $data);
    }

    /**
     * plan id => how many tenants are on it right now.
     *
     * @return array<int, int>
     */
    private function liveSubscriberCounts(): array
    {
        $currentIds = Subscription::query()
            ->selectRaw('MAX(id) as id')
            ->groupBy('tenant_id')
            ->pluck('id');

        return Subscription::query()
            ->whereIn('id', $currentIds)
            ->selectRaw('plan_id, COUNT(*) as aggregate')
            ->groupBy('plan_id')
            ->pluck('aggregate', 'plan_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    private function filtered(Request $request): Builder
    {
        return Plan::query()
            ->when($request->string('q')->toString(), function (Builder $query, string $term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

                $query->where(fn (Builder $q) => $q
                    ->where('name', 'like', $like)
                    ->orWhere('code', 'like', $like)
                    ->orWhere('blurb', 'like', $like));
            })
            ->when($request->string('status')->toString(), function (Builder $query, string $status) {
                $query->where('is_active', $status === 'active');
            })
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    /* ------------------------------------------------------ modal screens */

    public function create(): View
    {
        return view('admin.plans._form', [
            'plan' => new Plan(['currency' => 'INR', 'is_active' => true]),
            'modules' => Modules::catalogue(),
        ]);
    }

    public function edit(Plan $plan): View
    {
        return view('admin.plans._form', [
            'plan' => $plan,
            'modules' => Modules::catalogue(),
        ]);
    }

    public function show(Plan $plan): View
    {
        return view('admin.plans._show', [
            'plan' => $plan,
            'modules' => Modules::catalogue(),
            'live' => $this->liveSubscriberCounts()[$plan->id] ?? 0,
        ]);
    }

    /* ------------------------------------------------------------ writes */

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $plan = new Plan($data);
        $plan->slug = Plan::uniqueSlug(filled($data['slug'] ?? null) ? $data['slug'] : $data['name']);
        $plan->save();

        ActivityLog::record('plan.created', "Created plan \"{$plan->name}\"", $plan);

        return ApiResponse::success("Plan \"{$plan->name}\" created.");
    }

    public function update(Request $request, Plan $plan): JsonResponse
    {
        $data = $this->validated($request, $plan);

        $plan->fill($data)->save();

        ActivityLog::record('plan.updated', "Updated plan \"{$plan->name}\"", $plan);

        /*
         | An edit here changes what every subscriber on this plan may reach,
         | from the next request onwards. Nothing is copied onto their
         | subscriptions: the plan is read live, so adding a module to a plan
         | gives it to everybody on it, which is what "upgrading the plan"
         | means.
         |
         | The price is the one exception - see the subscriptions migration.
         | Existing subscribers keep the price they agreed to.
         */
        return ApiResponse::success("Plan \"{$plan->name}\" updated.");
    }

    public function destroy(Plan $plan): JsonResponse
    {
        if ($plan->subscriptions()->exists()) {
            return ApiResponse::error(
                "\"{$plan->name}\" has been sold, so it cannot be deleted - the subscriptions on it would lose what they refer to. "
                .'Switch it off instead: it keeps its existing subscribers and stops being offered to anybody new.'
            );
        }

        $name = $plan->name;
        $plan->delete();

        ActivityLog::record('plan.deleted', "Deleted plan \"{$name}\"", $plan);

        return ApiResponse::success("Plan \"{$name}\" deleted.");
    }

    /** Withdraw from sale, or put back. */
    public function toggleStatus(Plan $plan): JsonResponse
    {
        $plan->forceFill(['is_active' => ! $plan->is_active])->save();

        $state = $plan->is_active ? 'on sale' : 'withdrawn from sale';

        ActivityLog::record('plan.status', "Plan \"{$plan->name}\" is now {$state}", $plan);

        return ApiResponse::success("\"{$plan->name}\" is now {$state}.");
    }

    /* -------------------------------------------------------- validation */

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Plan $plan = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => [
                'required', 'string', 'max:40', 'alpha_dash',
                Rule::unique('plans', 'code')->ignore($plan?->id)->withoutTrashed(),
            ],
            'slug' => ['nullable', 'string', 'max:140'],
            'blurb' => ['nullable', 'string', 'max:255'],

            'monthly_price' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'yearly_price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'currency' => ['required', 'string', 'max:8'],
            'trial_days' => ['nullable', 'integer', 'min:0', 'max:365'],

            'modules' => ['nullable', 'array'],
            'modules.*' => ['string'],

            // Empty means unlimited, and an empty box is how somebody says
            // that. `nullable` rather than a "0 is unlimited" convention,
            // because zero is a real answer somebody might want.
            'max_shops' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'max_users' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'max_orders_per_month' => ['nullable', 'integer', 'min:0', 'max:100000000'],

            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);

        /*
         | "Every module" is expressed by sending nothing, and is stored as
         | null rather than the full list - so a module added next year
         | belongs to an unrestricted plan without anybody editing it.
         |
         | A plan deliberately stripped to nothing sends `modules_none`, and
         | is stored as []. The two cases have to stay distinguishable; a
         | form that could only say "these" would make "all, including
         | future ones" unexpressible.
         */
        $data['modules'] = $request->boolean('modules_all')
            ? null
            : Modules::sanitise($data['modules'] ?? []);

        $data['is_active'] = $request->boolean('is_active');
        $data['trial_days'] = (int) ($data['trial_days'] ?? 0);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);

        return $data;
    }
}
