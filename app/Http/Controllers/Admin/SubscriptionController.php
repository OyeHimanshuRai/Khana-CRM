<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Plan;
use App\Models\Shop;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Support\ApiResponse;
use App\Support\CurrentTenant;
use App\Support\PlanAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * Who is on what, and what they have paid (SRS 2, 4, 21).
 *
 * ---------------------------------------------------------------------------
 * One controller, two audiences
 * ---------------------------------------------------------------------------
 *
 *   index()   the platform's view: every business, its plan, its state and
 *             when it runs out. Super Admin.
 *
 *   mine()    a restaurant's own account, which the sitemap calls
 *             "Subscription / Plan". Read-only: the plan, what is left of
 *             the limits, the renewal date and the receipts.
 *
 * They are the same data seen from opposite sides of the invoice, which is
 * why they share a controller - and why every write here is gated on
 * `settings.subscriptions.edit`, which a Tenant Owner does not hold. A
 * customer who could extend their own term is not on a subscription.
 *
 * Reading is narrowed by CurrentTenant the same way TenantController
 * narrows it, so a Tenant Owner who reaches index() sees one row: their own.
 */
class SubscriptionController extends Controller
{
    private const PAGE_SIZES = [10, 15, 25, 50];

    public function __construct(private readonly SubscriptionService $subscriptions) {}

    /* ---------------------------------------------------- platform view */

    public function index(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 15;

        $tenants = $this->readable()
            ->with(['subscription.plan'])
            ->withCount(['shops', 'users'])
            ->when($request->string('q')->toString(), function (Builder $query, string $term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

                $query->where(fn (Builder $q) => $q
                    ->where('name', 'like', $like)
                    ->orWhere('code', 'like', $like)
                    ->orWhere('legal_name', 'like', $like));
            })
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();

        /*
         | Filtered after paging, not in SQL.
         |
         | State is computed from dates rather than stored, so there is no
         | column to filter on - see the subscriptions migration for why
         | that trade was worth making. The cost lands here, on one page of
         | rows, and it is the right place for it: a page of fifteen is
         | cheap to sift, and the alternative was a status column that lies.
         */
        $state = $request->string('state')->toString();

        $rows = $tenants->getCollection();

        if ($state !== '') {
            $rows = $rows->filter(fn (Tenant $t) => $state === 'none'
                ? $t->subscription === null
                : $t->subscription?->state() === $state);

            $tenants->setCollection($rows->values());
        }

        $data = [
            'tenants' => $tenants,
            'search' => $request->string('q')->toString(),
            'state' => $state,
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'states' => $this->stateOptions(),
            'stats' => $this->platformStats(),
            'canEdit' => $this->canEdit(),
        ];

        return $request->header('X-Fragment')
            ? view('admin.subscriptions._list', $data)
            : view('admin.subscriptions.index', $data);
    }

    /**
     * Headline figures for the platform.
     *
     * @return array<string, int|float>
     */
    private function platformStats(): array
    {
        $ids = CurrentTenant::accessibleIds() ?: [0];

        $current = Subscription::query()
            ->whereIn('tenant_id', $ids)
            ->whereIn('id', Subscription::query()
                ->selectRaw('MAX(id) as id')
                ->groupBy('tenant_id'))
            ->with('plan')
            ->get();

        return [
            'businesses' => Tenant::query()->whereIn('id', $ids)->count(),
            'subscribed' => $current->count(),
            'trialing' => $current->filter(fn (Subscription $s) => $s->state() === Subscription::TRIALING)->count(),
            'due' => $current->filter(fn (Subscription $s) => $s->needsAttention())->count(),
            'lapsed' => $current->filter(fn (Subscription $s) => ! $s->isUsable())->count(),
            /*
             | What the platform bills a month, at the prices agreed rather
             | than the prices advertised, and counting only accounts that
             | are actually running. A trial contributes nothing, because it
             | is not revenue until somebody pays.
             */
            'monthly' => $current
                ->filter(fn (Subscription $s) => $s->isUsable() && ! $s->onTrial())
                ->sum(fn (Subscription $s) => $s->billing_period === Plan::YEARLY
                    ? (float) $s->price / 12
                    : (float) $s->price),
        ];
    }

    /* ----------------------------------------------------- the customer's */

    /**
     * "Subscription / Plan" from the restaurant's own side.
     *
     * Deliberately works when there is no subscription at all, which is the
     * normal state of a single-restaurant install. It says so plainly rather
     * than showing an empty billing screen, because "you are not on a plan,
     * and nothing is limited" is a complete and reassuring answer.
     */
    public function mine(Request $request): View
    {
        $tenant = CurrentTenant::get();

        if ($tenant === null) {
            return view('admin.subscriptions.mine', [
                'tenant' => null,
                'subscription' => null,
                'usage' => [],
                'payments' => collect(),
                'canEdit' => false,
            ]);
        }

        $subscription = $tenant->subscription()->with('plan')->first();

        return view('admin.subscriptions.mine', [
            'tenant' => $tenant,
            'subscription' => $subscription,
            'usage' => $this->usageFor($tenant),
            'payments' => $this->paymentsFor($tenant),
            'canEdit' => $this->canEdit(),
        ]);
    }

    /**
     * What the business has used against what it bought.
     *
     * @return array<int, array<string, mixed>>
     */
    private function usageFor(Tenant $tenant): array
    {
        $plan = PlanAccess::subscriptionFor($tenant->id)?->plan;

        $shops = Shop::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->count();
        $users = User::query()->where('tenant_id', $tenant->id)->count();

        return [
            [
                'label' => 'Branches',
                'used' => $shops,
                'cap' => $plan?->max_shops,
            ],
            [
                'label' => 'Staff accounts',
                'used' => $users,
                'cap' => $plan?->max_users,
            ],
        ];
    }

    /** @return Collection<int, SubscriptionPayment> */
    private function paymentsFor(Tenant $tenant, int $limit = 50): Collection
    {
        return SubscriptionPayment::query()
            ->where('tenant_id', $tenant->id)
            ->with('recorder:id,name')
            ->latest('paid_at')
            ->limit($limit)
            ->get();
    }

    /* ------------------------------------------------------ modal screens */

    /** The full history and ledger for one business. */
    public function show(Tenant $tenant): View
    {
        $this->authoriseRead($tenant);

        return view('admin.subscriptions._show', [
            'tenant' => $tenant,
            'subscription' => $tenant->subscription()->with('plan')->first(),
            'history' => $tenant->subscriptions()->with('plan')->orderByDesc('id')->get(),
            'payments' => $this->paymentsFor($tenant),
            'usage' => $this->usageFor($tenant),
            'canEdit' => $this->canEdit(),
        ]);
    }

    /** Put a business on a plan, or move it to another one. */
    public function assign(Tenant $tenant): View
    {
        $this->authoriseRead($tenant);

        $current = $tenant->subscription()->with('plan')->first();

        return view('admin.subscriptions._assign', [
            'tenant' => $tenant,
            'subscription' => $current,
            /*
             | The outlets this business runs, each with what it is already
             | on. Subscriptions are sold per outlet, so this is the first
             | choice the form asks for - and showing the current plan beside
             | each one is what stops somebody selling the same branch twice.
             |
             | "The whole business" stays on the list as the blanket option,
             | because existing accounts are on exactly that and have to be
             | able to renew where they are.
             */
            'shops' => Shop::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->with(['subscription.plan'])
                ->orderBy('name')
                ->get(),
            // A withdrawn plan is still offered when it is the one they are
            // already on, so "renew where you are" does not become
            // impossible the day it leaves the price list.
            'plans' => Plan::query()
                ->where(fn (Builder $q) => $q
                    ->where('is_active', true)
                    ->orWhere('id', $current?->plan_id ?? 0))
                ->orderBy('sort_order')
                ->get(),
        ]);
    }

    /** Record money received against a business's subscription. */
    public function paymentForm(Tenant $tenant): View
    {
        $this->authoriseRead($tenant);

        $subscription = $tenant->subscription()->with('plan')->first();

        return view('admin.subscriptions._payment', [
            'tenant' => $tenant,
            'subscription' => $subscription,
            'methods' => SubscriptionPayment::METHODS,
        ]);
    }

    /* ------------------------------------------------------------ writes */

    public function store(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authoriseEdit();

        $data = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            /*
             | Which outlet is being sold to. Blank is the blanket row that
             | covers the whole business - still accepted so an account
             | taken out before per-outlet billing can renew where it is.
             |
             | Scoped to this tenant in the rule itself: without the where,
             | an id from somebody else's business would pass validation and
             | the service's own guard would be the only thing standing
             | between a typo and a cross-billed subscription.
             */
            'shop_id' => [
                'nullable', 'integer',
                Rule::exists('shops', 'id')->where('tenant_id', $tenant->id),
            ],
            'billing_period' => ['required', Rule::in(Plan::PERIODS)],
            'trial_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $plan = Plan::findOrFail($data['plan_id']);

        $shop = ! empty($data['shop_id'])
            ? Shop::query()->withoutGlobalScopes()->find($data['shop_id'])
            : null;

        try {
            $subscription = $this->subscriptions->subscribe(
                tenant: $tenant,
                plan: $plan,
                period: $data['billing_period'],
                trialDays: $request->filled('trial_days') ? (int) $data['trial_days'] : null,
                price: $request->filled('price') ? (float) $data['price'] : null,
                note: $data['note'] ?? null,
                shop: $shop,
            );
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        $sold = $shop?->name ?? $tenant->name;

        ActivityLog::record(
            'subscription.assigned',
            "Put \"{$sold}\" on the {$plan->name} plan",
            $subscription,
        );

        return ApiResponse::success(sprintf(
            '%s is on the %s plan%s.',
            $sold,
            $plan->name,
            $subscription->onTrial()
                ? ', on trial until '.$subscription->trial_ends_at->format('j M Y')
                : ($subscription->ends_at ? ', until '.$subscription->ends_at->format('j M Y') : ''),
        ));
    }

    public function recordPayment(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authoriseEdit();

        $subscription = $tenant->subscription()->first();

        if ($subscription === null) {
            return ApiResponse::error(
                "\"{$tenant->name}\" is not on a plan yet, so there is nothing to pay for. Put them on one first."
            );
        }

        $data = $request->validate([
            // Signed on purpose: a negative amount is a refund or a
            // correction, and it does not extend the term. See
            // SubscriptionService::recordPayment.
            'amount' => ['required', 'numeric', 'min:-99999999', 'max:99999999'],
            'method' => ['required', Rule::in(SubscriptionPayment::METHODS)],
            'provider_reference' => ['nullable', 'string', 'max:120'],
            'paid_at' => ['nullable', 'date'],
            'billing_period' => ['nullable', Rule::in(Plan::PERIODS)],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $payment = $this->subscriptions->recordPayment(
            subscription: $subscription,
            amount: (float) $data['amount'],
            method: $data['method'],
            providerReference: $data['provider_reference'] ?? null,
            paidAt: $request->filled('paid_at') ? now()->parse($data['paid_at']) : null,
            period: $data['billing_period'] ?? null,
            note: $data['note'] ?? null,
        );

        ActivityLog::record(
            'subscription.paid',
            sprintf('Recorded %s %s against "%s"', $payment->currency, number_format((float) $payment->amount, 2), $tenant->name),
            $payment,
        );

        $fresh = $subscription->fresh();

        return ApiResponse::success($payment->isRefund()
            ? sprintf('Refund of %s recorded. The term was not changed.', number_format(abs((float) $payment->amount), 2))
            : sprintf('Payment recorded. %s now runs until %s.', $tenant->name, $fresh->ends_at?->format('j M Y') ?? 'further notice'));
    }

    public function cancel(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authoriseEdit();

        $subscription = $tenant->subscription()->first();

        if ($subscription === null) {
            return ApiResponse::error("\"{$tenant->name}\" is not on a plan, so there is nothing to cancel.");
        }

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:255'],
            'immediately' => ['boolean'],
        ]);

        $immediately = $request->boolean('immediately');

        $this->subscriptions->cancel($subscription, $data['note'] ?? null, $immediately);

        ActivityLog::record(
            'subscription.cancelled',
            "Cancelled the subscription for \"{$tenant->name}\"",
            $subscription,
        );

        return ApiResponse::success($immediately
            ? "\"{$tenant->name}\" has been cancelled and can no longer sign in."
            : sprintf(
                'Cancelled. %s keeps working until %s, which they have already paid for.',
                $tenant->name,
                $subscription->cancelled_at?->format('j M Y') ?? 'the end of the term',
            ));
    }

    public function resume(Tenant $tenant): JsonResponse
    {
        $this->authoriseEdit();

        $subscription = $tenant->subscription()->first();

        if ($subscription === null || $subscription->cancelled_at === null) {
            return ApiResponse::error("\"{$tenant->name}\" has no cancellation to undo.");
        }

        $this->subscriptions->resume($subscription);

        ActivityLog::record(
            'subscription.resumed',
            "Reinstated the subscription for \"{$tenant->name}\"",
            $subscription,
        );

        return ApiResponse::success("\"{$tenant->name}\" is running again.");
    }

    /* ------------------------------------------------------------- guards */

    /** @return Builder<Tenant> */
    private function readable(): Builder
    {
        return Tenant::query()->whereIn('id', CurrentTenant::accessibleIds() ?: [0]);
    }

    /**
     * A reader may only ever open a business they can already reach.
     *
     * Route model binding does not know about tenancy, so without this a
     * Tenant Owner holding `settings.subscriptions.view` could read
     * somebody else's billing by editing the URL.
     */
    private function authoriseRead(Tenant $tenant): void
    {
        abort_unless(
            in_array($tenant->id, CurrentTenant::accessibleIds(), true),
            404,
        );
    }

    private function canEdit(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('settings.subscriptions.edit');
    }

    private function authoriseEdit(): void
    {
        abort_unless($this->canEdit(), 403, 'Changing a subscription is a platform action.');
    }

    /** @return array<string, string> */
    private function stateOptions(): array
    {
        return [
            Subscription::ACTIVE => 'Active',
            Subscription::TRIALING => 'Trial',
            Subscription::PAST_DUE => 'Payment due',
            Subscription::EXPIRED => 'Expired',
            Subscription::CANCELLED => 'Cancelled',
            'none' => 'No plan',
        ];
    }
}
