<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\PaymentIntent;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Subscription;
use App\Services\PaymentIntentService;
use App\Services\Payments\GatewayManager;
use App\Support\ApiResponse;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use App\Support\PlanAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * A restaurant paying for its own account (§11, §21).
 *
 * ---------------------------------------------------------------------------
 * The screen this is not
 * ---------------------------------------------------------------------------
 *
 * SubscriptionController is the platform's side of the deal: it assigns plans,
 * records payments taken elsewhere and cancels accounts, and every write on it
 * is gated on `settings.subscriptions.edit`, which a customer does not hold.
 * That is still right - a customer must not be able to extend their own term
 * by typing into a form.
 *
 * This is the other side: the customer hands money to the provider, the
 * provider says so, and the term moves because of that. Nothing here writes a
 * date. The money is what writes it, through PaymentIntentService::capture,
 * which both the browser and the webhook reach - so a phone that dies between
 * paying and the redirect still gets the outlet opened.
 *
 * ---------------------------------------------------------------------------
 * Reachable when the outlet is locked, on purpose
 * ---------------------------------------------------------------------------
 *
 * These routes sit outside `shop.subscribed`. An account whose term has run
 * out is exactly the account that needs this page, and a paywall you have to
 * be paid up to reach is a support ticket rather than a checkout.
 */
class BillingController extends Controller
{
    public function __construct(
        private readonly PaymentIntentService $payments,
        private readonly GatewayManager $gateways,
    ) {}

    /* --------------------------------------------------------------- read */

    public function show(): View
    {
        $subscription = $this->subscription();
        $shop = CurrentShop::get();

        return view('admin.billing.show', [
            'subscription' => $subscription,
            'plan' => $subscription?->plan,
            'shop' => $shop,
            'tenant' => CurrentTenant::get(),
            'payments' => $subscription
                ? $subscription->payments()->latest('paid_at')->limit(10)->get()
                : collect(),

            /*
             | Who to talk to when a card will not do it.
             |
             | Read from Company Settings rather than typed into the template,
             | because the one thing worse than no support number on a
             | billing page is a stale one.
             */
            'support' => [
                'email' => Setting::get('company_email'),
                'phone' => Setting::get('phone'),
            ],

            /*
             | Whether there is a provider at all. False is a working state,
             | not an error - the page then says how to pay instead of
             | drawing a button that cannot open anything. Same rule the
             | guest's table page follows. See config/payments.php.
             */
            'online' => $this->gateways->isLive(),
            'checkoutScript' => (string) config('payments.gateways.'.config('payments.gateway').'.checkout_js'),
        ]);
    }

    /* ------------------------------------------------------------ payment */

    /**
     * Open a checkout with the provider.
     *
     * The amount is the plan's own price for the period being bought, read
     * from the plans table on this request. It is never taken from the
     * browser: a price in a form field is a price somebody can edit.
     */
    public function checkout(Request $request): JsonResponse
    {
        $subscription = $this->subscription();

        if ($subscription === null) {
            return ApiResponse::error('There is no subscription on this outlet to pay for.');
        }

        $data = $request->validate([
            'period' => ['required', Rule::in(Plan::PERIODS)],
        ]);

        $plan = $subscription->plan;

        if ($plan === null) {
            return ApiResponse::error('This subscription has no plan on it. Please contact support.');
        }

        if (! $this->gateways->isLive()) {
            return ApiResponse::error(
                'Online payment is not switched on yet. Use the bank details on this page, '
                .'or ask us to take the payment for you.'
            );
        }

        try {
            $intent = $this->payments->open(
                $subscription,
                $plan->priceFor($data['period']),
                $subscription->shop_id ?? CurrentShop::id(),
            );
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        return ApiResponse::success('', [
            'reference' => $intent->reference,
            'amount' => (float) $intent->amount,
            'checkout' => $this->payments->checkoutFor($intent),
            'confirm_url' => route('admin.billing.confirm'),
        ]);
    }

    /**
     * The browser says it paid.
     *
     * Verified against a signature it cannot forge, then captured - and the
     * capture is idempotent, because the provider's webhook is racing this
     * request on every single payment. See PaymentIntentService.
     */
    public function confirm(Request $request): JsonResponse
    {
        $subscription = $this->subscription();

        if ($subscription === null) {
            return ApiResponse::error('There is no subscription on this outlet to pay for.');
        }

        $data = $request->validate([
            'reference' => ['required', 'string', 'max:64'],
            'razorpay_order_id' => ['nullable', 'string', 'max:120'],
            'razorpay_payment_id' => ['nullable', 'string', 'max:120'],
            'razorpay_signature' => ['nullable', 'string', 'max:255'],
        ]);

        /*
         | Found by reference *and* by this subscription. Without the second
         | half, a reference belonging to somebody else's account would
         | settle here - one business's payment extending another's term.
         */
        $intent = PaymentIntent::allShops()
            ->where('reference', $data['reference'])
            ->where('payable_type', $subscription->getMorphClass())
            ->where('payable_id', $subscription->id)
            ->first();

        if ($intent === null) {
            return ApiResponse::error('That payment does not belong to this account.', [], 404);
        }

        try {
            $intent = $this->payments->confirmFromBrowser($intent, $data);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        $fresh = $subscription->fresh();

        ActivityLog::record(
            'billing.paid',
            sprintf(
                'Paid ₹%s for "%s" online',
                number_format((float) $intent->amount, 2),
                CurrentShop::get()?->name ?? 'this outlet',
            ),
            $fresh,
        );

        return ApiResponse::success(
            sprintf(
                'Paid ₹%s. Your account runs until %s.',
                number_format((float) $intent->amount, 2),
                $fresh?->ends_at?->format('j M Y') ?? 'further notice',
            ),
            ['status' => $intent->status],
            route('admin.dashboard'),
        );
    }

    /* ------------------------------------------------------------ helpers */

    /**
     * The subscription this account is actually paying for.
     *
     * The outlet's own row first, because the product is priced per outlet.
     * PlanAccess falls back on its own to the tenant-wide blanket row for an
     * account taken out before per-outlet billing, which is exactly what
     * should be renewed where it exists.
     */
    private function subscription(): ?Subscription
    {
        $subscription = PlanAccess::subscriptionForShop(CurrentShop::id())
            ?? PlanAccess::subscriptionFor(CurrentTenant::id() ?? CurrentTenant::soleId());

        return $subscription?->loadMissing('plan');
    }
}
