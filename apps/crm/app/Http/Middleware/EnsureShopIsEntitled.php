<?php

namespace App\Http\Middleware;

use App\Models\Shop;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\CurrentShop;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Khana\Kit\Tenancy\Entitlements;
use Symfony\Component\HttpFoundation\Response;

/**
 * Has the outlet in context bought CRM? (`shop.entitled`)
 *
 * ---------------------------------------------------------------------------
 * Accounts decides, the kit remembers
 * ---------------------------------------------------------------------------
 *
 * Plans, subscriptions and payments are Khana Accounts' now. What reaches CRM
 * is the outcome - accounts.entitlement.changed, one per outlet and product -
 * which the kit writes to `khana_entitlements` and answers from through
 * Khana\Kit\Tenancy\Entitlements. So this gate reads nothing CRM owns except
 * which outlet the request is in: the outlet's key there is Accounts' outlet
 * id, which is `shops.ulid` here.
 *
 * The rules are the kit's (see Khana\Kit\Tenancy\EnsureEntitled):
 *
 *   trial, active, grace   through
 *   suspended              read only: looking and exporting are allowed,
 *                          anything that changes a row is refused
 *   never bought, ended    refused, with a sentence saying which
 *
 * and in the consolidated "All shops" view the product is usable when any of
 * the reader's outlets has it, because the consolidated screens only ever
 * read. The kit's own middleware is not used because it asks the kit's
 * tenancy Context whose outlet this is, and CRM still answers that with
 * CurrentShop and numeric shop ids.
 *
 * ---------------------------------------------------------------------------
 * One branch, not the business
 * ---------------------------------------------------------------------------
 *
 * Nothing here signs anybody out. A lapsed outlet is one branch of possibly
 * many, and the group's other restaurants are paid up and mid-service; the
 * person keeps their session, keeps every other branch, and can switch to
 * one - so signing out and switching are always let through. A suspended or
 * closed *business* is `tenant.active`'s refusal to make, and that one does
 * sign people out.
 *
 * ---------------------------------------------------------------------------
 * Who is not judged
 * ---------------------------------------------------------------------------
 *
 * Super Admin, like everywhere else: the reason to open a lapsed outlet is to
 * find out why it lapsed.
 *
 * Somebody who can reach no outlet at all. There is no outlet to judge, and
 * CurrentShop narrows every outlet-owned query to nothing for them, so what
 * is left is the platform's own screens, which their permissions decide.
 *
 * Runs after `tenant.active` and before `module`, which asks what this
 * branch may reach once it may use CRM at all.
 */
class EnsureShopIsEntitled
{
    /** The product this app is, in Accounts' words. */
    public const PRODUCT = 'crm';

    /**
     * Reachable whatever the outlet holds: the way out, and the way to an
     * outlet that is entitled.
     */
    private const ALWAYS = ['admin.logout', 'admin.shops.switch', 'admin.tenants.switch'];

    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->isSuperAdmin() || $request->routeIs(...self::ALWAYS)) {
            return $next($request);
        }

        $current = CurrentShop::get();

        /** @var Collection<int, Shop> $shops */
        $shops = $current !== null ? collect([$current]) : CurrentShop::accessible();

        if ($shops->isEmpty()) {
            return $next($request);
        }

        $usable = false;
        $held = false;

        foreach ($shops as $shop) {
            // A shop Accounts has never named cannot have been sold.
            if (blank($shop->ulid)) {
                continue;
            }

            $usable = $usable || $this->entitlements->allows($shop->ulid, self::PRODUCT);
            $held = $held || $this->entitlements->owns($shop->ulid, self::PRODUCT);
        }

        if ($usable || ($held && $request->isMethodSafe())) {
            return $next($request);
        }

        return $this->refuse($request, $this->message($current, $held));
    }

    /**
     * What went wrong and what to do about it, naming the branch - "not
     * subscribed" tells somebody they have a problem without telling them
     * which of their restaurants has it.
     */
    private function message(?Shop $shop, bool $held): string
    {
        if ($held) {
            return __(':shop is on a suspended Khana CRM subscription: you can look and export, but not change anything until it is renewed in Khana Accounts.', [
                'shop' => $shop?->name ?? 'Every outlet you work in',
            ]);
        }

        return $shop !== null
            ? __(':shop does not have Khana CRM, or its subscription has ended. Switch to another outlet, or ask the owner to add CRM for it in Khana Accounts.', [
                'shop' => $shop->name,
            ])
            : __('None of your outlets has Khana CRM at the moment. Ask the owner to add it in Khana Accounts.');
    }

    /**
     * A 403 rather than a redirect. Every other screen behind this gate is
     * refused too, so there is no page to send them to; the error page
     * carries the sentence above and the sign-out button.
     */
    private function refuse(Request $request, string $message): Response
    {
        if ($request->expectsJson()) {
            return ApiResponse::error($message, [], 403);
        }

        abort(403, $message);
    }
}
