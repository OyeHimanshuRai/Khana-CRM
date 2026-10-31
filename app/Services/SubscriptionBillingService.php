<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\BillingOrder;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Services\Payments\GatewayManager;
use App\Support\PlanAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A restaurant paying the platform online for a plan (SRS 2, 21).
 *
 * ---------------------------------------------------------------------------
 * Same rule as table payments: one capture, two ways in
 * ---------------------------------------------------------------------------
 *
 * The browser confirms and so does the provider's webhook; either may arrive
 * first. Both go through capture(), which locks the order row and does nothing
 * the second time, so a term is never extended twice for one payment.
 *
 * ---------------------------------------------------------------------------
 * Nothing is trusted from the browser
 * ---------------------------------------------------------------------------
 *
 * The amount comes from the plan's price and our own credit arithmetic, never
 * from the request. A payment is accepted only on a signature (browser) or a
 * signed webhook whose amount equals the order's. Both secrets belong to the
 * platform account, not any restaurant's.
 *
 * ---------------------------------------------------------------------------
 * Changing plan
 * ---------------------------------------------------------------------------
 *
 * Renewing the same plan extends the current term. Moving to a different plan
 * or period starts a fresh term from today, and the unused part of what was
 * last paid is credited against the new price. A credit larger than the price
 * is not refunded; the change is simply free for that term.
 */
class SubscriptionBillingService
{
    public function __construct(
        private readonly GatewayManager $gateways,
        private readonly SubscriptionService $subscriptions,
    ) {}

    /* -------------------------------------------------------------- pricing */

    /**
     * What it would cost to put this subscription on a plan and period.
     *
     * GST is added on what is actually being sold - the price less the
     * credit - at payments.invoice.gst_rate, so `amount` is what is charged.
     *
     * @return array{renewal: bool, list_price: float, credit: float, taxable: float, gst_rate: float, gst: float, amount: float, currency: string, new_ends_at: Carbon}
     */
    public function quote(Subscription $target, Plan $plan, string $period): array
    {
        $this->assertPurchasable($target, $plan, $period);

        $renewal = $target->plan_id === $plan->id && $target->billing_period === $period;

        // A renewal costs what this business agreed to, which may be a
        // negotiated rate or a price since changed. A move is at the plan's own.
        $listPrice = round($renewal ? (float) $target->price : $plan->priceFor($period), 2);
        $credit = $renewal ? 0.0 : min($listPrice, $this->unusedCredit($target));
        $taxable = max(0.0, round($listPrice - $credit, 2));
        $rate = max(0.0, (float) config('payments.invoice.gst_rate', 0));
        $gst = round($taxable * $rate / 100, 2);

        return [
            'renewal' => $renewal,
            'list_price' => $listPrice,
            'credit' => $credit,
            'taxable' => $taxable,
            'gst_rate' => $rate,
            'gst' => $gst,
            'amount' => round($taxable + $gst, 2),
            'currency' => $plan->currency,
            'new_ends_at' => $renewal
                ? Carbon::instance($target->extend($period))
                : $this->endOfTermFrom(Carbon::now(), $period),
        ];
    }

    /**
     * The unpaid-for remainder of the current term, in money.
     *
     * Worked out from the last payment that bought the running term - what was
     * actually paid for the days still ahead - and zero when there is no such
     * payment, or the term is a trial, cancelled, or already over.
     */
    private function unusedCredit(Subscription $target): float
    {
        if ($target->ends_at === null || ! $target->ends_at->isFuture()
            || $target->onTrial() || $target->cancelled_at !== null) {
            return 0.0;
        }

        $last = SubscriptionPayment::query()
            ->where('subscription_id', $target->id)
            ->where('amount', '>', 0)
            ->latest('period_end')
            ->first();

        if ($last === null || $last->period_start === null || $last->period_end === null) {
            return 0.0;
        }

        $length = $last->period_start->diffInSeconds($last->period_end, true);

        if ($length <= 0) {
            return 0.0;
        }

        $remaining = Carbon::now()->diffInSeconds($target->ends_at, true);
        $fraction = min(1.0, max(0.0, $remaining / $length));

        // What was paid for the plan itself: the tax on it was the government's, not a credit.
        return round(max(0.0, (float) $last->amount - (float) $last->tax_amount) * $fraction, 2);
    }

    private function endOfTermFrom(Carbon $from, string $period): Carbon
    {
        return $period === Plan::YEARLY ? $from->copy()->addYear() : $from->copy()->addMonth();
    }

    private function assertPurchasable(Subscription $target, Plan $plan, string $period): void
    {
        if (! in_array($period, Plan::PERIODS, true)) {
            throw new RuntimeException('That is not a billing period we offer.');
        }

        if (! $plan->isAvailableTo($target->tenant_id, $target->plan_id)) {
            throw new RuntimeException('That plan is no longer on sale.');
        }
    }

    /* ------------------------------------------------------------- checkout */

    /**
     * Start paying for a plan.
     *
     * A zero-amount change (a credit that covers the new price) needs no
     * provider at all and is applied on the spot.
     *
     * @throws RuntimeException with a message safe to show the customer
     */
    public function checkout(Subscription $target, Plan $plan, string $period, ?int $userId = null): BillingOrder
    {
        $quote = $this->quote($target, $plan, $period);

        if ($quote['amount'] <= 0) {
            if ($quote['renewal']) {
                throw new RuntimeException('This plan is free; there is nothing to pay.');
            }

            return $this->applyFreeChange($target, $plan, $period, $quote, $userId);
        }

        $gateway = $this->gateways->platform();

        if (! $gateway->isConfigured()) {
            throw new RuntimeException('Online payment is not set up yet. Please contact support to pay.');
        }

        $existing = BillingOrder::query()
            ->where('subscription_id', $target->id)
            ->where('plan_id', $plan->id)
            ->where('billing_period', $period)
            ->where('amount', $quote['amount'])
            ->open()
            ->whereNotNull('provider_order_id')
            ->latest('id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $order = BillingOrder::create([
            'tenant_id' => $target->tenant_id,
            'subscription_id' => $target->id,
            'plan_id' => $plan->id,
            'billing_period' => $period,
            'list_price' => $quote['list_price'],
            'credit' => $quote['credit'],
            'taxable_amount' => $quote['taxable'],
            'gst_rate' => $quote['gst_rate'],
            'gst_amount' => $quote['gst'],
            'amount' => $quote['amount'],
            'currency' => $quote['currency'],
            'reference' => BillingOrder::freshReference(),
            'provider' => $gateway->key(),
            'status' => BillingOrder::PENDING,
            'created_by' => $userId,
            'expires_at' => now()->addMinutes((int) config('payments.intent_ttl_minutes', 30)),
        ]);

        $providerOrder = $gateway->createProviderOrder($order->amount, $order->reference, [
            'billing_order' => (string) $order->id,
            'tenant' => (string) $order->tenant_id,
        ]);

        $providerOrderId = (string) ($providerOrder['id'] ?? '');

        if ($providerOrderId === '') {
            $order->forceFill(['status' => BillingOrder::FAILED, 'failure_reason' => 'Provider returned no order id.'])->save();

            throw new RuntimeException('The payment could not be started. Please try again.');
        }

        $order->forceFill([
            'provider_order_id' => $providerOrderId,
            'payload' => $gateway->checkoutOptions($providerOrderId, $order->amount),
        ])->save();

        return $order->refresh();
    }

    /* ------------------------------------------------------------ capturing */

    /**
     * The browser says it paid. Check the signature, then capture.
     *
     * @param  array<string, mixed>  $payload
     */
    public function confirmFromBrowser(BillingOrder $order, array $payload): BillingOrder
    {
        $orderId = (string) ($payload['razorpay_order_id'] ?? '');
        $paymentId = (string) ($payload['razorpay_payment_id'] ?? '');
        $signature = (string) ($payload['razorpay_signature'] ?? '');

        // The order id must be the one this order opened, or a valid
        // callback for somebody else's payment could settle ours.
        $valid = $orderId !== ''
            && hash_equals((string) $order->provider_order_id, $orderId)
            && $this->gateways->platform()->signatureMatches($orderId, $paymentId, $signature);

        if (! $valid) {
            if (! $order->isPaid()) {
                $order->forceFill(['status' => BillingOrder::FAILED, 'failure_reason' => 'The payment could not be verified.'])->save();
            }

            throw new RuntimeException('That payment could not be verified. If money was taken it will be applied automatically; otherwise contact support.');
        }

        return $this->capture($order, $paymentId, 'browser');
    }

    /**
     * The provider says it paid. Returns null for anything not acted on.
     *
     * @param  array<string, string>  $headers
     */
    public function confirmFromWebhook(string $body, array $headers): ?BillingOrder
    {
        $parsed = $this->gateways->platform()->parseWebhook($body, $headers);

        if ($parsed === null || ! $parsed['paid'] || $parsed['provider_order_id'] === '') {
            return null;
        }

        $order = BillingOrder::query()
            ->where('provider_order_id', $parsed['provider_order_id'])
            ->first();

        if ($order === null) {
            return null;
        }

        if (abs($parsed['amount'] - (float) $order->amount) > 0.009) {
            report(new RuntimeException(sprintf(
                'Billing webhook amount %s does not match order %s (%s).',
                $parsed['amount'], $order->reference, $order->amount,
            )));

            return null;
        }

        return $this->capture($order, $parsed['provider_payment_id'], 'webhook');
    }

    /**
     * The subscription a paid order should land on, today.
     *
     * Usually the one it was opened against. But a checkout can sit open in a
     * second tab while the plan changes underneath it, and paying it then
     * would extend a closed row: the money taken, the business none the
     * better. So it lands on what the business is on now.
     */
    private function currentFor(Subscription $target): Subscription
    {
        return Subscription::query()
            ->where('tenant_id', $target->tenant_id)
            ->where('shop_id', $target->shop_id)
            ->latest('id')
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Mark it paid and extend the term, exactly once.
     *
     * A "failed" order can still be captured: a browser-side verification
     * failure must not stop a genuine, signed webhook from being honoured.
     */
    public function capture(BillingOrder $order, string $providerPaymentId, string $via): BillingOrder
    {
        $providerPaymentId = trim($providerPaymentId);

        if ($providerPaymentId === '') {
            throw new RuntimeException('A captured payment must name the provider payment.');
        }

        return DB::transaction(function () use ($order, $providerPaymentId, $via) {
            $locked = BillingOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->isPaid()) {
                return $locked;
            }

            $payment = $this->apply($locked, $providerPaymentId);

            $locked->forceFill([
                'status' => BillingOrder::PAID,
                'provider_payment_id' => $providerPaymentId,
                'failure_reason' => null,
                'paid_at' => now(),
                'subscription_payment_id' => $payment->id,
            ])->save();

            ActivityLog::record(
                'billing.captured',
                sprintf('%s paid ₹%s for a plan online (%s, via the %s)',
                    $locked->reference, number_format((float) $locked->amount, 2), $providerPaymentId, $via),
                $locked,
            );

            return $locked->refresh();
        });
    }

    /* --------------------------------------------------------------- applying */

    /** Write the subscription side of a paid order. */
    private function apply(BillingOrder $order, string $providerPaymentId): SubscriptionPayment
    {
        $target = $this->currentFor(Subscription::query()->findOrFail($order->subscription_id));
        $plan = Plan::query()->findOrFail($order->plan_id);

        $renewal = $target->plan_id === $plan->id && $target->billing_period === $order->billing_period;

        $subscription = $renewal
            ? $target
            : $this->replace($target, $plan, $order->billing_period, (float) $order->list_price, 'Changed online ('.$order->reference.')');

        $payment = $this->subscriptions->recordPayment(
            $subscription,
            (float) $order->amount,
            'razorpay',
            $providerPaymentId,
            null,
            $order->billing_period,
            $order->credit > 0
                ? sprintf('%s; ₹%s credited for unused time', $order->reference, number_format((float) $order->credit, 2))
                : $order->reference,
        );

        // Kept on the payment so the next credit is worked out before tax.
        $payment->forceFill(['tax_amount' => (float) $order->gst_amount])->save();

        PlanAccess::forget();

        return $payment;
    }

    /**
     * Close a subscription and open its replacement on another plan.
     *
     * The new row's term starts at "now", so the payment recorded straight
     * after extends from today rather than from the old end date: the unused
     * time was already settled as a credit.
     */
    private function replace(Subscription $old, Plan $plan, string $period, float $price, string $note): Subscription
    {
        $old->forceFill(['cancelled_at' => now(), 'note' => $note])->save();

        return Subscription::create([
            'tenant_id' => $old->tenant_id,
            'shop_id' => $old->shop_id,
            'plan_id' => $plan->id,
            'billing_period' => $period,
            'price' => $price,
            'currency' => $plan->currency,
            'starts_at' => now(),
            'trial_ends_at' => null,
            'ends_at' => now(),
            'grace_days' => $old->grace_days,
            'note' => $note,
            'created_by' => $old->created_by,
        ]);
    }

    /**
     * A plan change the credit fully covers: no money, no provider.
     *
     * @param  array{credit: float, list_price: float, new_ends_at: Carbon}  $quote
     */
    private function applyFreeChange(Subscription $target, Plan $plan, string $period, array $quote, ?int $userId): BillingOrder
    {
        return DB::transaction(function () use ($target, $plan, $period, $quote, $userId) {
            $target = Subscription::query()->lockForUpdate()->findOrFail($target->id);

            $order = BillingOrder::create([
                'tenant_id' => $target->tenant_id,
                'subscription_id' => $target->id,
                'plan_id' => $plan->id,
                'billing_period' => $period,
                'list_price' => $quote['list_price'],
                'credit' => $quote['credit'],
                'amount' => 0,
                'currency' => $plan->currency,
                'reference' => BillingOrder::freshReference(),
                'provider' => 'credit',
                'status' => BillingOrder::PAID,
                'created_by' => $userId,
                'paid_at' => now(),
            ]);

            $new = $this->replace($target, $plan, $period, $quote['list_price'], 'Changed with credit ('.$order->reference.')');

            // A free plan has no renewal to pay, so it must not be given an end
            // to reach: nothing could ever bring it back. See subscribe().
            $new->forceFill(['ends_at' => $quote['list_price'] > 0 ? $quote['new_ends_at'] : null])->save();

            $this->subscriptions->reflectOnTenant($new);
            PlanAccess::forget();

            return $order;
        });
    }
}
