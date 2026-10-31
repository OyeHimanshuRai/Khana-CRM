<?php

namespace App\Services;

use App\Mail\GuestThanksMail;
use App\Mail\SubscriptionPaidMail;
use App\Models\Alert;
use App\Models\Customer;
use App\Models\Feedback;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Shop;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\TableSession;
use App\Models\User;
use App\Services\Sms\SmsManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Who hears about what, in one place.
 *
 *   event                 the bell (+ push)                  email / SMS
 *   ------------------    -------------------------------    ------------------------
 *   a guest's order       staff who can see orders           -
 *   guest feedback        staff who can see feedback         -
 *   a sign-up             the platform                       (SignupMailer)
 *   a plan paid for       the platform                       the business's owners
 *   a table settled       -                                  the guest, if they gave
 *                                                            an email or a mobile
 *
 * Every method swallows its own failures. Each is called after the thing it
 * reports has already happened and been committed, and telling people about
 * an order must never be the reason the order fails.
 *
 * The alerts go through AlertService::raise, which pushes a new one to the
 * devices of whoever may see it (AlertPushService) - so a bell row and a
 * phone notification can never disagree about who was told.
 */
class EventNotifier
{
    public function __construct(private readonly AlertService $alerts) {}

    /** A ticket from a table, the storefront or the API. */
    public function orderPlaced(Order $order): void
    {
        $this->safely('order', function () use ($order) {
            $shop = Shop::query()->withoutGlobalScopes()->find($order->shop_id);

            if ($shop === null) {
                return;
            }

            $order->loadMissing('tableSession.table');
            $table = $order->tableSession?->table;

            $where = $table !== null
                ? 'Table '.$table->name
                : ($order->order_type === Order::DELIVERY ? 'Delivery' : ($order->order_type === Order::TAKEAWAY ? 'Takeaway' : 'Online'));

            $items = (int) $order->items()->sum('quantity');

            $this->alerts->raise(
                $shop,
                Alert::ORDER_PLACED,
                "New order {$order->order_number} · {$where}",
                'order:'.$order->id,
                sprintf('%d item%s · ₹%s%s', $items, $items === 1 ? '' : 's',
                    number_format((float) $order->grand_total, 2),
                    $order->guest_name ? ' · '.$order->guest_name : ''),
                $order->table_session_id
                    ? route('admin.table-bills.show', $order->table_session_id, false)
                    : route('admin.live-orders.index', [], false),
                $order,
                can: 'sales.orders.view',
                expiresAt: now()->addDay(),
            );
        });
    }

    /** A guest told us how it was. A poor rating is worth a louder alert. */
    public function feedbackReceived(Feedback $feedback): void
    {
        $this->safely('feedback', function () use ($feedback) {
            $shop = Shop::query()->withoutGlobalScopes()->find($feedback->shop_id);

            if ($shop === null) {
                return;
            }

            $rating = (int) $feedback->rating;

            $this->alerts->raise(
                $shop,
                Alert::FEEDBACK,
                sprintf('%s feedback: %d/5', $rating <= 2 ? 'Unhappy' : 'New', $rating),
                'feedback:'.$feedback->id,
                $feedback->comment ? \Illuminate\Support\Str::limit((string) $feedback->comment, 160) : null,
                route('admin.feedback.show', $feedback->id, false),
                $feedback,
                can: 'crm.feedback.view',
                level: $rating <= 2 ? 'warning' : 'info',
            );
        });
    }

    /** A new business signed itself up. For the platform's eyes only. */
    public function signedUp(User $owner): void
    {
        $this->safely('signup', function () use ($owner) {
            $shop = Shop::query()->withoutGlobalScopes()->where('tenant_id', $owner->tenant_id)->orderBy('id')->first();

            if ($shop === null) {
                return;
            }

            $this->alerts->raise(
                $shop,
                Alert::SIGNUP,
                'New sign-up: '.($owner->tenant?->name ?? $shop->name),
                'signup:'.$owner->tenant_id,
                trim($owner->name.' · '.$owner->email.($owner->mobile ? ' · '.$owner->mobile : '')),
                route('admin.tenants.show', $owner->tenant_id, false),
                $owner->tenant,
                // Platform-only (PlatformAbilities), so the new owner never
                // sees an alert about themselves.
                can: 'settings.tenants.create',
            );
        });
    }

    /**
     * Somebody asked for a demo on the landing page: a lead, for the
     * platform's eyes only.
     *
     * Filed on the platform's own first branch - an alert needs a branch,
     * and a lead belongs to no restaurant yet. Gated on a platform-only
     * right, so no restaurant's staff ever see another business's leads.
     */
    public function demoRequested(\App\Models\DemoRequest $lead): void
    {
        $this->safely('demo_request', function () use ($lead) {
            $shop = Shop::query()->withoutGlobalScopes()->orderBy('id')->first();

            if ($shop === null) {
                return;
            }

            $this->alerts->raise(
                $shop,
                Alert::DEMO_REQUEST,
                'Demo request: '.($lead->business_name ?: $lead->name),
                'demo_request:'.$lead->id,
                trim(implode(' · ', array_filter([$lead->name, $lead->phone, $lead->email, $lead->city]))),
                route('admin.demo-requests.show', $lead->id, false),
                $lead,
                can: 'content.demo_requests.view',
            );
        });
    }

    /**
     * A lapsed business asked to renew from its billing screen - the way back
     * in when the platform takes no payment online. For the platform's eyes
     * only, linked to the support ticket that carries the request.
     */
    public function renewalRequested(\App\Models\SupportTicket $ticket, \App\Models\Plan $plan, string $period): void
    {
        $this->safely('renewal_request', function () use ($ticket, $plan, $period) {
            $shop = Shop::query()->withoutGlobalScopes()->where('tenant_id', $ticket->tenant_id)->orderBy('id')->first();

            if ($shop === null) {
                return;
            }

            $this->alerts->raise(
                $shop,
                Alert::RENEWAL_REQUEST,
                'Renewal request: '.($ticket->tenant?->name ?? $shop->name),
                'renewal_request:'.$ticket->id,
                sprintf('%s · %s · %s', $plan->name, $period, $ticket->reference),
                route('admin.support.show', $ticket, false),
                $ticket,
                can: 'settings.subscriptions.edit',
            );
        });
    }

    /** Money in for a plan: a receipt to the owners, an alert to us. */
    public function subscriptionPaid(SubscriptionPayment $payment): void
    {
        if ((float) $payment->amount <= 0) {
            return;
        }

        $this->safely('subscription_paid', function () use ($payment) {
            $payment->loadMissing(['subscription.tenant', 'subscription.plan', 'subscription.shop']);
            $subscription = $payment->subscription;

            if ($subscription === null) {
                return;
            }

            foreach (app(SubscriptionNoticeService::class)->recipientsFor($subscription) as $to) {
                Mail::to($to['email'], $to['name'])->send(new SubscriptionPaidMail($payment, $to['name']));
            }

            $shop = $subscription->shop
                ?? Shop::query()->withoutGlobalScopes()->where('tenant_id', $subscription->tenant_id)->orderBy('id')->first();

            if ($shop !== null) {
                $this->alerts->raise(
                    $shop,
                    Alert::SUBSCRIPTION_PAID,
                    sprintf('Payment received: %s %s', $payment->currency, number_format((float) $payment->amount, 2)),
                    'subscription_payment:'.$payment->id,
                    trim(($subscription->tenant?->name ?? '').' · '.($subscription->plan?->name ?? '').' · '.$payment->reference),
                    route('admin.tenants.show', $subscription->tenant_id, false),
                    $payment,
                    can: 'settings.subscriptions.edit',
                    level: 'success',
                );
            }
        });
    }

    /**
     * The table has paid: thank the guest, if they left a way to reach them.
     *
     * An email goes to a customer record's own address. Failing that, a text
     * to the mobile the guest typed - but only when an SMS provider is really
     * connected, never to the log driver, so nobody believes a message was
     * sent that was not.
     *
     * "First visit" is asked of this branch's own bills: a regular at one
     * outlet is still new at the one across town.
     */
    public function billSettled(TableSession $session, Invoice $invoice): void
    {
        $this->safely('guest_thanks', function () use ($session, $invoice) {
            $customer = $session->customer_id
                ? Customer::query()->withoutGlobalScopes()->find($session->customer_id)
                : null;

            $mobile = $customer?->mobile ?: $session->guest_mobile;
            $email = $customer?->email;

            if (blank($email) && blank($mobile)) {
                return;
            }

            $firstVisit = $this->isFirstVisit($invoice, $customer, $mobile);
            $invoice->loadMissing('shop');

            if (filled($email)) {
                Mail::to($email, $invoice->customer_name ?: null)
                    ->send(new GuestThanksMail($invoice, $firstVisit));

                return;
            }

            $sms = app(SmsManager::class);

            if ($sms->isLive()) {
                $shopName = $invoice->shop?->name ?? 'us';

                $sms->gateway()->send((string) $mobile, $firstVisit
                    ? "Thank you for your first visit to {$shopName}! We hope to see you again soon."
                    : "Thank you for dining with {$shopName} again! See you soon.");
            }
        });
    }

    /* ------------------------------------------------------------ helpers */

    private function isFirstVisit(Invoice $invoice, ?Customer $customer, ?string $mobile): bool
    {
        $earlier = Invoice::query()
            ->withoutGlobalScopes()
            ->where('shop_id', $invoice->shop_id)
            ->where('id', '<', $invoice->id)
            ->where('status', '!=', Invoice::CANCELLED);

        if ($customer !== null) {
            return ! (clone $earlier)->where('customer_id', $customer->id)->exists();
        }

        return ! (clone $earlier)->where('customer_mobile', $mobile)->exists();
    }

    private function safely(string $what, callable $work): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            report($e);
            Log::warning('Notification failed', ['event' => $what, 'error' => $e->getMessage()]);
        }
    }
}
