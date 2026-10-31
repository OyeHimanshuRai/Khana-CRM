<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\PlanCatalogue;
use App\Support\ApiResponse;
use App\Support\Modules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
            ->with('client')
            ->paginate($perPage)
            ->withQueryString();

        /*
         | Subscriber counts, but only the ones that currently matter.
         |
         | `subscriptions_count` above counts history - every row a plan
         | change ever left behind - and showing that as "subscribers" would
         | say a withdrawn plan has forty customers when it has none. So the
         | live figure is counted separately, from the rows still running.
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
            'kind' => $request->string('kind')->toString(),
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'stats' => [
                'total' => Plan::query()->count(),
                'active' => Plan::query()->onSale()->count(),
                'subscribers' => array_sum($live),
            ],
        ];

        return $request->header('X-Fragment')
            ? view('admin.plans._list', $data)
            : view('admin.plans.index', $data);
    }

    /**
     * plan id => how many subscriptions on it are running right now.
     *
     * State is worked out from dates in PHP, so a lapsed business stops
     * counting without anything having to flip a flag.
     *
     * @return array<int, int>
     */
    private function liveSubscriberCounts(): array
    {
        return Subscription::query()
            ->current()
            ->get()
            ->filter(fn (Subscription $subscription) => $subscription->isUsable())
            ->countBy('plan_id')
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
            ->when($request->string('kind')->toString(), function (Builder $query, string $kind) {
                $kind === 'custom' ? $query->whereNotNull('tenant_id') : $query->whereNull('tenant_id');
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
            'clients' => $this->clients(),
        ]);
    }

    public function edit(Plan $plan): View
    {
        return view('admin.plans._form', [
            'plan' => $plan,
            'modules' => Modules::catalogue(),
            'clients' => $this->clients(),
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

    /** Every business, for the "who is this plan for" list. */
    private function clients(): \Illuminate\Support\Collection
    {
        return Tenant::query()->orderBy('name')->get(['id', 'name']);
    }

    /* ------------------------------------------------- the modules matrix */

    /** Every module against every plan, general and custom: who gets what, at a glance. */
    public function modules(Request $request): View
    {
        return view('admin.plans.modules', [
            'modules' => Modules::catalogue(),
            'plans' => Plan::query()
                ->with('client')
                ->orderByRaw('tenant_id IS NOT NULL')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'canEdit' => $request->user()->can('settings.plans.edit'),
        ]);
    }

    public function updateModules(Request $request, PlanCatalogue $catalogue): JsonResponse
    {
        $data = $request->validate([
            'grants' => ['present', 'array'],
            'grants.*' => ['array'],
            'grants.*.*' => ['nullable', 'string'],
        ]);

        $changed = 0;

        Plan::query()->whereKey(array_keys($data['grants']))->get()->each(function (Plan $plan) use ($data, $catalogue, &$changed) {
            $chosen = Modules::sanitise(array_filter($data['grants'][$plan->id]));

            if ($chosen !== $plan->moduleKeys()) {
                $catalogue->setModules($plan, $chosen);
                $changed++;
                ActivityLog::record('plan.modules', "Changed the modules on plan \"{$plan->name}\"", $plan);
            }
        });

        return ApiResponse::success($changed === 0 ? 'Nothing to change.' : "Saved. {$changed} plan(s) updated.");
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

    public function update(Request $request, Plan $plan, PlanCatalogue $catalogue): JsonResponse
    {
        $data = $this->validated($request, $plan);
        $modules = $data['modules'];
        unset($data['modules']);

        $plan->fill($data)->save();
        $catalogue->setModules($plan, $modules);

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

        // The row stays, soft-deleted; its code and slug go back into use.
        $plan->forceFill([
            'code' => substr($plan->code, 0, 24).'-deleted-'.$plan->id,
            'slug' => substr($plan->slug, 0, 120).'-deleted-'.$plan->id,
        ])->save();
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
            // Plans are priced in what the platform's payment account takes.
            'currency' => ['required', 'string', Rule::in([(string) config('payments.platform.currency', 'INR')])],
            'trial_days' => ['nullable', 'integer', 'min:0', 'max:365'],

            'modules' => ['nullable', 'array'],
            'modules.*' => ['nullable', 'string'],

            // Empty for a general plan; a business for a custom one.
            'client_id' => ['nullable', 'integer', Rule::exists('tenants', 'id')->whereNull('deleted_at')],

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
         | A plan names its modules, one by one. "Every module" means every one
         | there is today: a module added next year joins this plan when the
         | platform adds it, not by itself. A request that does not mention
         | modules leaves them as they are; the form always sends the key.
         */
        $data['modules'] = match (true) {
            $request->boolean('modules_all') => Modules::keys(),
            $request->has('modules') => Modules::sanitise(array_filter($data['modules'] ?? [])),
            default => $plan?->moduleKeys() ?? [],
        };

        // A free year on a paid plan is a typing slip, not a price.
        if ((float) $data['monthly_price'] > 0 && $request->filled('yearly_price') && (float) $data['yearly_price'] <= 0) {
            throw ValidationException::withMessages(['yearly_price' => 'Leave this empty to charge twelve months, or enter the yearly price.']);
        }

        /*
         | Who a plan is for is settled once it has been sold: moving it would
         | put a stranger's plan on somebody's account, or a private deal on
         | the price list.
         */
        $client = $data['client_id'] ?? null;
        unset($data['client_id']);

        if ($plan !== null && $request->has('client_id') === false) {
            $client = $plan->tenant_id;
        }

        if ($plan !== null && (int) $client !== (int) $plan->tenant_id && $plan->subscriptions()->exists()) {
            throw ValidationException::withMessages(['client_id' => 'This plan has been sold, so who it is for can no longer change.']);
        }

        $data['tenant_id'] = $client;

        $data['is_active'] = $request->boolean('is_active');
        $data['trial_days'] = (int) ($data['trial_days'] ?? 0);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);

        return $data;
    }
}
