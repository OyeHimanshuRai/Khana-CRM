<?php

namespace App\Http\Middleware;

use App\Models\Subscription;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\CurrentShop;
use App\Support\PlanAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stop an outlet whose own subscription has run out (SRS 2, 21).
 *
 * ---------------------------------------------------------------------------
 * One branch, not the business
 * ---------------------------------------------------------------------------
 *
 * Subscriptions are sold per outlet, so this is the gate that enforces them -
 * and the whole reason it exists apart from `tenant.active` is that the two
 * refusals are different sizes. A suspended *business* is an account-level
 * matter and signs everybody out. A lapsed *outlet* is one branch of possibly
 * many, and the group's other restaurants are paid up and mid-service.
 *
 * So nothing here logs anybody out. The user keeps their session, keeps every
 * other branch they have access to, and is told to switch or renew. Signing
 * them out would take four working restaurants offline to collect for a fifth.
 *
 * ---------------------------------------------------------------------------
 * What it does NOT do
 * ---------------------------------------------------------------------------
 *
 * No subscription at all is not a refusal. An install that predates billing,
 * and any outlet nobody has sold anything to, keeps working exactly as it did -
 * the same rule PlanAccess states and for the same reason. This gate speaks
 * only when somebody bought something and it has run out.
 *
 * Past due is not a refusal either. Inside the grace window the branch keeps
 * trading; locking a kitchen out over an invoice a few days late is how a
 * platform loses the customer rather than collects from them.
 *
 * Super Admin is exempt, like everywhere else: the person whose job is to fix
 * a lapsed account has to be able to open it.
 *
 * Runs after `tenant.active`, which has already dealt with the business as a
 * whole, and before `module`, which asks what this branch may reach.
 *
 * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
 */
class EnsureShopIsSubscribed
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->isSuperAdmin()) {
            return $next($request);
        }

        /*
         | In All-shops mode there is no single outlet to judge, and the
         | consolidated screens read across every branch the user may see.
         | Refusing here would block a reader for a branch they were not
         | looking at; the per-shop screens below hit this gate on their own.
         */
        $shopId = CurrentShop::id();

        if ($shopId === null) {
            return $next($request);
        }

        $subscription = PlanAccess::subscriptionForShop($shopId);

        // Never sold, or still inside its term or its grace window.
        if ($subscription === null || $subscription->isUsable()) {
            return $next($request);
        }

        /*
         | Only an outlet's OWN subscription stops that outlet.
         |
         | A blanket row reaching here means the whole business has lapsed,
         | and that is `tenant.active`'s refusal to make - it signs people
         | out, which is right at account level and wrong here. Letting it
         | past keeps one refusal for one situation.
         */
        if (! $subscription->isPerShop()) {
            return $next($request);
        }

        return $this->refuse($request, $subscription);
    }

    /**
     * Send them somewhere they can act, without ending their session.
     *
     * The message names the branch and the date, because "subscription
     * expired" tells somebody they have a problem without telling them which
     * of their restaurants has it.
     */
    private function refuse(Request $request, Subscription $subscription): Response
    {
        $shop = $subscription->shop()->first();
        $name = $shop?->name ?? 'This outlet';

        $message = $subscription->state() === Subscription::CANCELLED
            ? __('The subscription for :shop has been cancelled. Renew it, or switch to another outlet.', [
                'shop' => $name,
            ])
            : __('The subscription for :shop ended on :date. Renew it, or switch to another outlet.', [
                'shop' => $name,
                'date' => $subscription->ends_at?->format('j M Y') ?? '',
            ]);

        if ($request->expectsJson()) {
            return ApiResponse::error($message, [], 403);
        }

        /*
         | Back to the dashboard rather than to a dead end. The switcher lives
         | in the header there, so the two things this message tells them to
         | do are both one click away.
         |
         | Guarded against a loop: if the dashboard is itself the blocked
         | request, the message goes to billing instead.
         |
         | Billing and not the subscription screen, for two reasons. It is
         | outside this gate, so it can actually be opened by the account
         | this middleware just refused - sending them to another gated route
         | bounced them between two pages that both redirect. And it is the
         | page that ends the situation: an owner told their outlet has
         | lapsed wants to pay for it, not to read about it.
         */
        if ($request->routeIs('admin.dashboard')) {
            /*
             | Billing is behind settings.subscriptions.view, which the owner
             | holds and a cashier does not. Somebody who cannot open it has
             | no way out to be sent to, so they are told plainly and once -
             | redirecting them to another gated screen is how this ended up
             | bouncing between two pages that both redirect.
             */
            if ($request->user()?->can('settings.subscriptions.view') !== true) {
                abort(403, $message);
            }

            return redirect()->route('admin.billing.show')->with('error', $message);
        }

        return redirect()->route('admin.dashboard')->with('error', $message);
    }
}
