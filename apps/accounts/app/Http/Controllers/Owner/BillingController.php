<?php

namespace App\Http\Controllers\Owner;

use App\Models\Outlet;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Subscriptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * What each outlet is on, and what it owes. An owner pays, changes an
 * outlet's plan, or drops one from here; every change reaches CRM and ERP as
 * an entitlement, in the same commit.
 */
final class BillingController
{
    public function index(Request $request): View
    {
        $tenant = $request->user()->tenant;
        $tenant->loadMissing(['outlets.subscriptions.plan', 'outlets.subscriptions.payments']);

        return view('owner.billing.index', [
            'outlets' => $tenant->outlets,
            'plans' => Plan::active()->get(),
        ]);
    }

    public function pay(Subscription $subscription, Request $request, Subscriptions $subscriptions): RedirectResponse
    {
        $this->authorizeSubscription($subscription, $request);

        $data = $request->validate([
            'method' => ['required', Rule::in(\App\Models\PlatformPayment::METHODS)],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);

        $subscriptions->recordPayment($subscription, $data['method'], $data['reference'] ?? null, by: $request->user());

        return back()->with('status', 'Payment recorded and the term moved forward.');
    }

    public function changePlan(Subscription $subscription, Request $request, Subscriptions $subscriptions): RedirectResponse
    {
        $this->authorizeSubscription($subscription, $request);

        $data = $request->validate([
            'plan' => ['required', Rule::exists('plans', 'code')->where('is_active', true)],
            'period' => ['required', Rule::in(Plan::PERIODS)],
        ]);

        $plan = Plan::where('code', $data['plan'])->firstOrFail();
        $subscriptions->changePlan($subscription, $plan, $data['period'], $request->user());

        return back()->with('status', 'Plan changed. The new plan reaches your products now.');
    }

    public function cancel(Subscription $subscription, Request $request, Subscriptions $subscriptions): RedirectResponse
    {
        $this->authorizeSubscription($subscription, $request);

        $subscriptions->cancel($subscription, $request->input('note'));

        return back()->with('status', 'This outlet\'s plan will end when the paid term is up.');
    }

    private function authorizeSubscription(Subscription $subscription, Request $request): void
    {
        abort_unless($subscription->tenant_id === $request->user()->tenant_id, 404);
    }
}
