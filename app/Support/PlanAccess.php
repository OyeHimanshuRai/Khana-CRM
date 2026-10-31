<?php

namespace App\Support;

use App\Models\Order;
use App\Models\Shop;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * What a business's plan lets it do (SRS 2, 21).
 *
 * ---------------------------------------------------------------------------
 * A ceiling, not a switch
 * ---------------------------------------------------------------------------
 *
 * A plan caps what a tenant may reach; it never turns anything on. A branch
 * still picks its own modules, and what it actually gets is the overlap -
 * see Modules::forShop. Selling a restaurant the inventory module does not
 * mean its takeaway counter wants a stock ledger.
 *
 * The same rule applies to the numbers. A plan's `max_shops` is checked when
 * somebody tries to add a branch, never when they try to read one. Limits
 * that bite at read time lock people out of data they own and have already
 * paid for, which is how a billing feature turns into a support queue.
 *
 * ---------------------------------------------------------------------------
 * Null is unlimited, and no subscription is unlimited
 * ---------------------------------------------------------------------------
 *
 * An install that was running before any of this existed has no plans, no
 * subscriptions and no intention of being a SaaS. It must carry on working
 * exactly as it did. So every lookup here returns "no ceiling" when there is
 * no subscription to read, and the feature is invisible until somebody sells
 * something.
 *
 * ---------------------------------------------------------------------------
 * Sold per outlet, with a blanket row underneath
 * ---------------------------------------------------------------------------
 *
 * A subscription belongs to a shop - the public pricing page has always said
 * "Per outlet", and this is where that becomes true. But rows taken out
 * before the change carry no shop_id and cover the whole business, so there
 * are two shapes and an order to look in:
 *
 *   1. the shop's own subscription    (shop_id = this shop)
 *   2. the tenant's blanket row       (shop_id null)
 *   3. nothing                        -> no ceiling, as above
 *
 * This is the only class that knows that order. Everything else asks it a
 * question about a shop or about a tenant and gets one answer.
 *
 * The consequence worth stating: one outlet's subscription lapsing is that
 * outlet's problem. It does not touch its neighbours, and it must never log
 * anybody out of the whole account - see EnsureTenantIsActive, which reads
 * the blanket row only, and EnsureShopIsSubscribed, which reads the shop's.
 */
final class PlanAccess
{
    /** tenant id => blanket subscription, or false for "looked, found none". */
    private static array $subscriptions = [];

    /** shop id => resolved subscription, or false for "looked, found none". */
    private static array $shopSubscriptions = [];

    /**
     * The business-wide subscription for a tenant, or null.
     *
     * Blanket rows ONLY - a subscription sold for one outlet is not the
     * business's subscription, and returning it here would let a single
     * lapsed branch lock the whole account out at the door.
     *
     * Memoised per request including the misses, because the sidebar asks
     * once per menu entry and a tenant without a subscription is the
     * commonest case on a single-restaurant install.
     */
    public static function subscriptionFor(?int $tenantId): ?Subscription
    {
        if ($tenantId === null) {
            return null;
        }

        if (array_key_exists($tenantId, self::$subscriptions)) {
            return self::$subscriptions[$tenantId] ?: null;
        }

        $subscription = Subscription::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('shop_id')
            ->latest('id')
            ->with('plan')
            ->first();

        self::$subscriptions[$tenantId] = $subscription ?: false;

        return $subscription;
    }

    /**
     * What covers one outlet: its own subscription, or the blanket row.
     *
     * The shop's own row wins even when it has expired and the blanket is
     * still running. Somebody who bought this branch its own subscription
     * has moved it off the blanket, and quietly falling back would sell them
     * the same outlet twice - the fallback is for outlets that never had one,
     * not for ones whose invoice is late.
     */
    public static function subscriptionForShop(?int $shopId): ?Subscription
    {
        if ($shopId === null) {
            return null;
        }

        if (array_key_exists($shopId, self::$shopSubscriptions)) {
            return self::$shopSubscriptions[$shopId] ?: null;
        }

        $own = Subscription::query()
            ->where('shop_id', $shopId)
            ->latest('id')
            ->with('plan')
            ->first();

        if ($own === null) {
            // Only now is the tenant asked, and only for its blanket row.
            $tenantId = Shop::query()->withoutGlobalScopes()->whereKey($shopId)->value('tenant_id');
            $own = self::subscriptionFor($tenantId === null ? null : (int) $tenantId);
        }

        self::$shopSubscriptions[$shopId] = $own ?: false;

        return $own;
    }

    /** The subscription for the business the request is working inside. */
    public static function current(): ?Subscription
    {
        return self::subscriptionFor(CurrentTenant::id());
    }

    /**
     * The subscription a customer pays for from the billing screen: the
     * outlet they are working in, else the business-wide one.
     */
    public static function billingTarget(): ?Subscription
    {
        return self::currentShop() ?? self::current();
    }

    /** What covers the outlet the request is working inside. */
    public static function currentShop(): ?Subscription
    {
        return self::subscriptionForShop(CurrentShop::id());
    }

    /**
     * The modules a tenant's plan grants, or null for no ceiling.
     *
     * Null in two cases, both of which mean "do not narrow anything":
     *
     *   - no subscription, so this install is not being sold;
     *   - the reader is a Super Admin.
     *
     * The second is the same exemption EnsureTenantIsActive makes, for the
     * same reason: the person whose job is to fix an account has to be able
     * to open it, and a support call that ends in "I can't see that screen
     * either" helps nobody.
     *
     * @return array<int, string>|null
     */
    public static function modulesFor(?int $tenantId): ?array
    {
        $user = Auth::user();

        if ($user instanceof User && $user->isSuperAdmin()) {
            return null;
        }

        return self::subscriptionFor($tenantId)?->plan?->moduleKeys();
    }

    /**
     * The modules one outlet's own plan grants, or null for no ceiling.
     *
     * The per-outlet twin of modulesFor(). Two branches of one business may
     * now be on different plans, so asking the tenant would give the bar
     * next door's answer - which is the whole point of selling per outlet.
     *
     * @return array<int, string>|null
     */
    public static function modulesForShop(?int $shopId): ?array
    {
        $user = Auth::user();

        if ($user instanceof User && $user->isSuperAdmin()) {
            return null;
        }

        return self::subscriptionForShop($shopId)?->plan?->moduleKeys();
    }

    /* ------------------------------------------------------------ limits */

    /**
     * How many more branches this tenant may create; null for unlimited.
     *
     * Counts what exists now rather than trusting a stored tally. A count
     * that drifts from the rows it counts is worse than no count, and a
     * business does not add branches often enough for the query to matter.
     */
    /**
     * How many more branches this tenant may create; null for unlimited.
     *
     * Only a blanket subscription caps this, and that is the point of
     * selling per outlet: when every branch carries its own subscription
     * there is no number of branches being sold, so there is nothing to cap.
     * A new outlet is added freely and then subscribed - see
     * EnsureShopIsSubscribed, which is where an unpaid outlet is actually
     * stopped.
     *
     * Counts what exists now rather than trusting a stored tally. A count
     * that drifts from the rows it counts is worse than no count, and a
     * business does not add branches often enough for the query to matter.
     */
    public static function remainingShops(?int $tenantId): ?int
    {
        $cap = self::subscriptionFor($tenantId)?->plan?->max_shops;

        if ($cap === null || $tenantId === null) {
            return null;
        }

        $used = Shop::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->count();

        return max(0, $cap - $used);
    }

    /**
     * How many more staff accounts; null for unlimited.
     *
     * Counts accounts that can sign in: a plan sells seats, and somebody who
     * has left is not sitting in one. Switching them back on takes a seat
     * again, so UserSecurityController asks here first.
     */
    public static function remainingUsers(?int $tenantId): ?int
    {
        $cap = self::subscriptionFor($tenantId)?->plan?->max_users;

        if ($cap === null || $tenantId === null) {
            return null;
        }

        $used = User::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->count();

        return max(0, $cap - $used);
    }

    public static function canAddShop(?int $tenantId): bool
    {
        $left = self::remainingShops($tenantId);

        return $left === null || $left > 0;
    }

    public static function canAddUser(?int $tenantId): bool
    {
        $left = self::remainingUsers($tenantId);

        return $left === null || $left > 0;
    }

    /**
     * How many more orders this outlet may take this calendar month; null
     * for unlimited.
     *
     * The cap belongs to the subscription that covers the outlet. An outlet
     * with its own subscription is counted alone; one riding the tenant's
     * blanket row shares the allowance with its sister branches, because
     * that is what the blanket plan sold. Cancelled orders do not count -
     * a mistyped ticket should not eat into what somebody paid for.
     */
    public static function remainingOrders(?int $shopId): ?int
    {
        $subscription = self::subscriptionForShop($shopId);
        $cap = $subscription?->plan?->max_orders_per_month;

        if ($cap === null || $shopId === null) {
            return null;
        }

        $shopIds = $subscription->isPerShop()
            ? [$shopId]
            : Shop::query()->withoutGlobalScopes()
                ->where('tenant_id', $subscription->tenant_id)
                ->pluck('id')->all();

        $used = Order::query()
            ->withoutGlobalScopes()
            ->whereIn('shop_id', $shopIds)
            ->where('status', '!=', Order::CANCELLED)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        /*
         | And the counter's own sales - a walk-in rung up at the till is an
         | order taken, and on a Starter plan nearly all of them. A table's
         | bill (it carries its sitting) and an online order's invoice are
         | not: their orders were counted above when they were placed.
         */
        $used += \App\Models\Invoice::query()
            ->withoutGlobalScopes()
            ->whereIn('shop_id', $shopIds)
            ->whereIn('channel', [\App\Models\Invoice::POS, \App\Models\Invoice::MANUAL])
            ->whereNull('table_session_id')
            ->where('status', '!=', \App\Models\Invoice::CANCELLED)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        return max(0, $cap - $used);
    }

    /**
     * Refuse a new order the outlet's subscription does not cover.
     *
     * Two reasons, and both are about taking new business: the subscription
     * has lapsed, or the month's allowance is spent. Reading and settling
     * what already exists is never blocked here.
     *
     * Throws RuntimeException because every order entry point (table QR,
     * storefront, API) already turns that into a message for its own caller.
     * Super Admin is exempt, as everywhere else in this class.
     *
     * @throws \RuntimeException
     */
    public static function assertCanTakeOrder(?int $shopId): void
    {
        $user = Auth::user();

        if ($shopId === null || ($user instanceof User && $user->isSuperAdmin())) {
            return;
        }

        if (! self::isTrading($shopId)) {
            throw new \RuntimeException('This outlet is not taking orders right now.');
        }

        if (self::remainingOrders($shopId) === 0) {
            throw new \RuntimeException('This outlet has reached its order limit for the month.');
        }
    }

    /**
     * Is this outlet open for business to the public right now?
     *
     * The one question every door a guest walks through asks - the
     * storefront, the QR code on the table, an order arriving by API - so
     * they cannot disagree. Three things must hold: the outlet is active, its
     * company is not suspended (by a person or by the billing sweep), and the
     * subscription covering it is usable. Asked of the shop directly, never
     * of whoever is signed in, because a guest is nobody.
     */
    public static function isTrading(int $shopId): bool
    {
        $shop = Shop::query()->withoutGlobalScopes()
            ->with(['tenant' => fn ($q) => $q->withoutGlobalScopes()])
            ->find($shopId);

        if ($shop === null || ! $shop->is_active) {
            return false;
        }

        if ($shop->tenant?->isSuspended()) {
            return false;
        }

        $subscription = self::subscriptionForShop($shopId);

        return $subscription === null || $subscription->isUsable();
    }

    /**
     * Why an addition was refused, in the words a person needs.
     *
     * Names the plan and the number, because "limit reached" tells somebody
     * they have a problem without telling them what to do about it.
     */
    public static function refusalFor(?int $tenantId, string $what): string
    {
        $subscription = self::subscriptionFor($tenantId);
        $plan = $subscription?->plan;

        $cap = (int) ($what === 'shop' ? $plan?->max_shops : $plan?->max_users);

        $noun = $what === 'shop'
            ? ($cap === 1 ? 'branch' : 'branches')
            : ($cap === 1 ? 'staff account' : 'staff accounts');

        /*
         | "1 branches" is the sort of thing a person notices and a system
         | never does, and it lands in front of somebody who is already
         | being refused something.
         */
        return sprintf(
            'The %s plan includes %d %s, and %s in use. Upgrade the plan to add more.',
            $plan?->name ?? 'current',
            $cap,
            $noun,
            $cap === 1 ? 'it is' : 'all of them are',
        );
    }

    /** What the pricing page says about tax, from the rate billing actually charges. */
    public static function gstLine(): string
    {
        $rate = (float) config('payments.invoice.gst_rate', 0);

        return $rate > 0
            ? rtrim(rtrim(number_format($rate, 2), '0'), '.').'% GST is added at checkout.'
            : 'Prices include all taxes.';
    }

    /**
     * Forget everything - after a tenant switch, a plan change, and between
     * tests.
     */
    public static function forget(): void
    {
        self::$subscriptions = [];
        self::$shopSubscriptions = [];
    }
}
