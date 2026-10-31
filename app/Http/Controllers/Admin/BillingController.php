<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BillingOrder;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Services\SubscriptionBillingService;
use App\Services\SubscriptionService;
use App\Support\CurrentTenant;
use App\Support\PlanAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A restaurant paying for, renewing or changing its own plan online.
 *
 * JSON endpoints for the billing screen to call. The amount is never read
 * from the request: the request names a plan and a period, and the price is
 * worked out here from the plan and the current term.
 */
class BillingController extends Controller
{
    public function __construct(
        private readonly SubscriptionBillingService $billing,
        private readonly SubscriptionService $subscriptions,
    ) {}

    public function quote(Request $request): JsonResponse
    {
        [$target, $plan, $period] = $this->resolve($request);

        try {
            $quote = $this->billing->quote($target, $plan, $period);
        } catch (RuntimeException $e) {
            return json_error($e->getMessage());
        }

        return json_success('Quote ready.', [
            'renewal' => $quote['renewal'],
            'list_price' => $quote['list_price'],
            'credit' => $quote['credit'],
            'taxable' => $quote['taxable'],
            'gst_rate' => $quote['gst_rate'],
            'gst' => $quote['gst'],
            'amount' => $quote['amount'],
            'currency' => $quote['currency'],
            'new_ends_at' => $quote['new_ends_at']->toIso8601String(),
        ]);
    }

    public function checkout(Request $request): JsonResponse
    {
        [$target, $plan, $period] = $this->resolve($request);

        try {
            $order = $this->billing->checkout($target, $plan, $period, $request->user()->id);
        } catch (RuntimeException $e) {
            return json_error($e->getMessage());
        }

        // A change the credit covered is already applied; there is no
        // checkout to open.
        if ($order->isPaid()) {
            return json_success('Your plan has been changed.', [
                'reference' => $order->reference,
                'paid' => true,
            ], route('admin.subscription.mine'));
        }

        return json_success('Checkout ready.', [
            'reference' => $order->reference,
            'paid' => false,
            'amount' => $order->amount,
            'currency' => $order->currency,
            'checkout' => $order->payload,
        ]);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:64'],
            'razorpay_order_id' => ['required', 'string', 'max:120'],
            'razorpay_payment_id' => ['required', 'string', 'max:120'],
            'razorpay_signature' => ['required', 'string', 'max:255'],
        ]);

        $order = BillingOrder::query()
            ->where('reference', $data['reference'])
            ->where('tenant_id', CurrentTenant::id())
            ->firstOrFail();

        try {
            $this->billing->confirmFromBrowser($order, $data);
        } catch (RuntimeException $e) {
            return json_error($e->getMessage());
        }

        return json_success('Payment received. Your subscription is up to date.', [], route('admin.subscription.mine'));
    }

    /**
     * The subscription being paid for, the plan chosen and the period.
     *
     * @return array{0: Subscription, 1: Plan, 2: string}
     */
    private function resolve(Request $request): array
    {
        $data = $request->validate([
            'plan_id' => ['required', 'integer'],
            'period' => ['required', 'string', 'in:'.implode(',', Plan::PERIODS)],
        ]);

        // Whether this business may have it is SubscriptionBillingService's question.
        return [$this->target(), Plan::query()->findOrFail($data['plan_id']), $data['period']];
    }

    private function target(): Subscription
    {
        $target = PlanAccess::billingTarget();

        abort_if(
            $target === null || $target->tenant_id !== CurrentTenant::id(),
            422,
            'There is no subscription to pay for yet. Please contact support to get started.',
        );

        return $target;
    }

    /* ------------------------------------------------------ renewal request */

    /**
     * Ask the platform to renew, from the billing screen.
     *
     * The way back in when nothing can be paid online: without it, a lapsed
     * owner was left looking at "contact support" with no way to do so from
     * an account that opens nothing but this screen. Filed as a support
     * ticket so it is answered where every other billing question is, and
     * raised on the platform's bell so it is not left waiting. One open
     * request at a time - asking again only says it is already in hand.
     */
    public function requestRenewal(
        Request $request,
        \App\Services\SupportTicketService $tickets,
        \App\Services\EventNotifier $notifier,
    ): JsonResponse {
        $data = $request->validate([
            'plan_id' => ['required', 'integer'],
            'period' => ['required', 'string', 'in:'.implode(',', Plan::PERIODS)],
        ]);

        $tenant = CurrentTenant::get();
        abort_if($tenant === null, 422, 'Your account is not linked to a company.');

        $plan = Plan::query()
            ->availableTo($tenant->id, PlanAccess::billingTarget()?->plan_id)
            ->find($data['plan_id']);

        if ($plan === null) {
            $message = 'That plan is no longer available. Please choose another.';

            return json_error($message, ['plan_id' => [$message]]);
        }

        $open = \App\Models\SupportTicket::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('category', 'billing')
            ->where('subject', 'like', 'Renewal request:%')
            ->whereIn('status', \App\Models\SupportTicket::ACTIVE_STATUSES)
            ->first();

        if ($open !== null) {
            return json_success("We already have your renewal request ({$open->reference}). Our team will be in touch shortly.");
        }

        $current = PlanAccess::billingTarget();

        $ticket = $tickets->open([
            'tenant_id' => $tenant->id,
            'shop_id' => \App\Support\CurrentShop::id(),
            'category' => 'billing',
            'priority' => \App\Models\SupportTicket::HIGH,
            'subject' => sprintf('Renewal request: %s (%s)', $plan->name, $data['period']),
            'body' => sprintf(
                "%s would like to continue on the %s plan, billed %s (%s %s).\n\nCurrent plan: %s, %s.\nAsked by %s (%s).",
                $tenant->name,
                $plan->name,
                $data['period'],
                $plan->currency,
                number_format($plan->priceFor($data['period']), 2),
                $current?->plan?->name ?? 'none',
                $current?->ends_at ? 'ended '.$current->ends_at->format('j M Y') : 'no end date',
                $request->user()->name,
                $request->user()->email,
            ),
        ], $request->user());

        $notifier->renewalRequested($ticket, $plan, $data['period']);

        return json_success(
            "Request sent ({$ticket->reference}). Our team will confirm the renewal and get you back to work.",
            [],
            route('admin.subscription.mine'),
        );
    }

    /* ------------------------------------------------- cancel and resume */

    /** Stop renewing; the paid term keeps running to its end. */
    public function cancel(Request $request): JsonResponse
    {
        $target = $this->target();

        if ($target->cancelled_at !== null) {
            return json_error('This subscription is already cancelled.');
        }

        // A term that is over has nothing left to stop renewing; "keep working
        // until" a date in the past would only confuse.
        if (! $target->isUsable()) {
            return json_error('This subscription has already ended, so there is nothing to cancel.');
        }

        $this->subscriptions->cancel($target, 'Cancelled by the customer', false);
        PlanAccess::forget();

        return json_success(
            $target->ends_at
                ? 'Cancelled. You can keep working until '.$target->ends_at->format('j M Y').'.'
                : 'Cancelled.',
            [],
            route('admin.subscription.mine'),
        );
    }

    /** Undo a cancellation that has not taken effect yet. */
    public function resume(Request $request): JsonResponse
    {
        $target = $this->target();

        if ($target->cancelled_at === null || ! $target->cancelled_at->isFuture()) {
            return json_error('Only a cancellation that has not started yet can be undone. Please renew instead.');
        }

        $this->subscriptions->resume($target);
        PlanAccess::forget();

        return json_success('Welcome back. Your subscription will renew as normal.', [], route('admin.subscription.mine'));
    }

    /* ----------------------------------------------------------- invoices */

    /** The printable receipt for one payment. */
    public function invoice(SubscriptionPayment $payment): View
    {
        abort_unless($payment->tenant_id === CurrentTenant::id(), 404);
        abort_if($payment->isRefund(), 404);

        return view('admin.subscriptions.invoice', [
            'payment' => $payment->load('subscription.plan', 'tenant'),
            'order' => BillingOrder::query()->where('subscription_payment_id', $payment->id)->first(),
            'seller' => config('payments.invoice'),
        ]);
    }
}
