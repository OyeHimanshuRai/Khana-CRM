<?php

namespace App\Support;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Who is working in which shop, right now.
 *
 * Every tenant-scoped query goes through this class, so there is exactly one
 * answer to "which shop's data may this request see" and one place to audit
 * it. The rules, in full:
 *
 *   - Super Admin may reach every active shop.
 *   - Everyone else may reach only the shops on their `shop_user` pivot.
 *     That pivot row *is* the "explicit authorization" the SRS requires.
 *   - The selected shop id may be NULL, meaning "All shops". That is a
 *     consolidated *view*, not an escape hatch: the query scope still
 *     narrows to accessibleIds(), so a two-shop manager in All-shops mode
 *     sees those two shops and no others.
 *
 * Resolution is memoised per request because the scope asks on every query.
 */
final class CurrentShop
{
    /** Set once resolve() has run; distinct from "resolved to null". */
    private static bool $resolved = false;

    private static ?int $shopId = null;

    /** @var Collection<int, Shop>|null */
    private static ?Collection $accessible = null;

    /**
     * The shop the request is working in, or null for All shops.
     */
    public static function id(): ?int
    {
        if (! self::$resolved) {
            self::resolve();
        }

        return self::$shopId;
    }

    public static function get(): ?Shop
    {
        $id = self::id();

        return $id === null
            ? null
            : self::accessible()->firstWhere('id', $id);
    }

    /**
     * Shop ids this user may read, whichever shop is selected.
     *
     * @return array<int, int>
     */
    public static function accessibleIds(): array
    {
        return self::accessible()->pluck('id')->all();
    }

    /**
     * Every shop this user may switch into, ordered as the switcher shows them.
     *
     * @return Collection<int, Shop>
     */
    public static function accessible(): Collection
    {
        if (self::$accessible !== null) {
            return self::$accessible;
        }

        $user = Auth::user();

        if (! $user instanceof User) {
            return self::$accessible = collect();
        }

        /*
         | Inactive shops stay in the list on purpose. Deactivating a branch
         | closes it for business, but its ledger, invoices and stock history
         | must not vanish from the people who have to reconcile them - so
         | the switcher shows it, marked, rather than hiding the data.
         */
        $query = $user->isSuperAdmin()
            ? Shop::query()
            : $user->shops();

        /*
         | Tenant isolation, and the single place it is enforced.
         |
         | Every operational table filters on shop_id already. Narrowing the
         | reachable shops to the tenant in context therefore narrows all of
         | them at once, which is why tenant_id is not repeated across forty
         | tables: there is one rule here rather than forty that could each
         | be forgotten.
         |
         | Null means the consolidated view, which only a Super Admin can be
         | in - see CurrentTenant - so leaving the list unfiltered there is
         | the intended behaviour, not a hole.
         */
        $tenantId = CurrentTenant::id();

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        } elseif (! $user->isSuperAdmin()) {
            // Staff with no tenant belong to no business and can reach
            // nothing. Without this they would fall through to "unfiltered".
            return self::$accessible = collect();
        }

        return self::$accessible = $query
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /** May this user work in that shop at all? */
    public static function canAccess(int $shopId): bool
    {
        return in_array($shopId, self::accessibleIds(), true);
    }

    /**
     * Whether the consolidated "All shops" option should be offered.
     *
     * Only meaningful with more than one shop to consolidate.
     */
    public static function canSeeAll(): bool
    {
        return self::accessible()->count() > 1;
    }

    /**
     * Switch the active shop and remember it on the account.
     *
     * Persisted rather than kept in the session so a cashier does not have
     * to re-pick their till on every device.
     *
     * @param  int|null  $shopId  null selects All shops
     */
    public static function set(?int $shopId): bool
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return false;
        }

        if ($shopId !== null && ! self::canAccess($shopId)) {
            return false;
        }

        if ($shopId === null && ! self::canSeeAll()) {
            return false;
        }

        // The flag is what makes "chose All shops" distinguishable from
        // "has never chosen" on the next request.
        $user->forceFill([
            'current_shop_id' => $shopId,
            'all_shops_view' => $shopId === null,
        ])->save();

        self::$shopId = $shopId;
        self::$resolved = true;

        // The branch just changed, so which lines of business are switched on
        // has too. Not routed through forget(), which would throw away the
        // answer that was just established.
        Modules::forget();

        return true;
    }

    /**
     * Shop id to stamp on a row being created.
     *
     * In All-shops mode there is no single answer, so the caller has to have
     * chosen one - which is why every create form carries a shop field once
     * more than one shop exists.
     */
    public static function idForWrite(): ?int
    {
        return self::id() ?? (self::accessible()->count() === 1
            ? self::accessible()->first()->id
            : null);
    }

    /**
     * Forget the memoised answer.
     *
     * Needed after a sign-in, after the pivot changes, and between tests.
     *
     * Takes the module memo with it, and that coupling is the point: which
     * lines of business are switched on is derived entirely from the shop in
     * context, so a stale module answer beside a fresh shop is a bug waiting
     * to be found in an access check. Making the two impossible to forget
     * separately is cheaper than remembering to.
     */
    public static function forget(): void
    {
        self::$resolved = false;
        self::$shopId = null;
        self::$accessible = null;

        Modules::forget();
    }

    /**
     * Work out the active shop from the account, falling back sensibly.
     *
     * The stored choice is re-checked against accessible() every request:
     * revoking someone's access to a shop has to take effect immediately,
     * not the next time they happen to switch.
     */
    private static function resolve(): void
    {
        self::$resolved = true;

        $user = Auth::user();

        if (! $user instanceof User) {
            self::$shopId = null;

            return;
        }

        $accessible = self::accessible();

        if ($accessible->isEmpty()) {
            self::$shopId = null;

            return;
        }

        /*
         | The consolidated view, but only while there is more than one shop
         | to consolidate. Someone whose access has been narrowed to a single
         | branch since they chose it drops back to that branch.
         */
        if ($user->all_shops_view && $accessible->count() > 1) {
            self::$shopId = null;

            return;
        }

        $stored = $user->current_shop_id;

        if ($stored !== null && $accessible->contains('id', $stored)) {
            self::$shopId = (int) $stored;

            return;
        }

        /*
         | Never chosen, or chose a shop they have since lost. Open on the
         | pivot default so a cashier assigned to three branches lands on
         | their own till rather than somewhere arbitrary.
         */
        $default = $user->shops()->wherePivot('is_default', true)->value('shops.id');

        self::$shopId = $default !== null && $accessible->contains('id', (int) $default)
            ? (int) $default
            : $accessible->first()->id;
    }
}
