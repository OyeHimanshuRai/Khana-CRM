<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Which business the request is working inside.
 *
 * The tier above CurrentShop, and the same idea one level up. The rules, in
 * full:
 *
 *   - A Super Admin belongs to no tenant. They may reach every tenant, and
 *     may sit in the consolidated "all tenants" view.
 *   - Everyone else belongs to exactly one tenant, the one on their account,
 *     and can never reach another. There is no pivot and no switcher for
 *     them, because there is nothing to switch to - which is the point.
 *   - A suspended tenant is still visible to a Super Admin, so an unpaid
 *     account can be looked at and put right, but its own staff are locked
 *     out. See EnsureTenantIsActive.
 *
 * Nothing here filters operational rows directly. It decides which *shops*
 * are reachable, and CurrentShop's scope does the rest - one tenant check
 * standing in front of forty tables that already filter on shop_id.
 *
 * Resolution is memoised per request, as the scope asks on every query.
 */
final class CurrentTenant
{
    /** Set once resolve() has run; distinct from "resolved to null". */
    private static bool $resolved = false;

    private static ?int $tenantId = null;

    /** @var Collection<int, Tenant>|null */
    private static ?Collection $accessible = null;

    /**
     * The tenant in context, or null for the all-tenants view.
     */
    public static function id(): ?int
    {
        if (! self::$resolved) {
            self::resolve();
        }

        return self::$tenantId;
    }

    public static function get(): ?Tenant
    {
        $id = self::id();

        return $id === null ? null : self::accessible()->firstWhere('id', $id);
    }

    /**
     * Tenant ids this user may read, whichever one is selected.
     *
     * @return array<int, int>
     */
    public static function accessibleIds(): array
    {
        return self::accessible()->pluck('id')->all();
    }

    /**
     * Every tenant this user may work inside.
     *
     * @return Collection<int, Tenant>
     */
    public static function accessible(): Collection
    {
        if (self::$accessible !== null) {
            return self::$accessible;
        }

        $user = Auth::user();

        /*
         | Not memoised, deliberately.
         |
         | "Nobody is signed in" is not a stable answer: somebody can appear
         | later in the same process. In production that is a sign-in, where
         | the request continues with a user the tenant check then has to see;
         | in tests it is every actingAs() after a seeder ran unauthenticated.
         | Caching the empty answer would leave whoever arrives next belonging
         | to no company - which, now that EnsureTenantIsActive reads this,
         | means turned away at the door.
         */
        if (! $user instanceof User) {
            return collect();
        }

        if ($user->isSuperAdmin()) {
            /*
             | Suspended tenants stay in the list for a Super Admin: the
             | reason to open a suspended account is precisely to fix why it
             | was suspended. The switcher marks them.
             */
            return self::$accessible = Tenant::query()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get();
        }

        $ownId = $user->tenant_id === null
            ? self::soleId()
            : (int) $user->tenant_id;

        $own = $ownId === null
            ? null
            : Tenant::query()->whereKey($ownId)->first();

        return self::$accessible = $own ? collect([$own]) : collect();
    }

    /**
     * The only company, when there is only one.
     *
     * An install with a single business has no ambiguity to resolve: an
     * account with no company named on it belongs to that one, because there
     * is nothing else for it to belong to. Accounts created before the tenants
     * table existed are in exactly this state until TenantSeeder adopts them.
     *
     * Returns null the moment a second business exists, which is what keeps
     * this from ever being a way into somebody else's data - an unassigned
     * account then reaches nothing at all. Read by both id() and accessible(),
     * so the two can never disagree about who a user is, and by Shop, which
     * needs the same answer with no authenticated user at all.
     */
    public static function soleId(): ?int
    {
        return Tenant::query()->count() === 1
            ? (int) Tenant::query()->value('id')
            : null;
    }

    public static function canAccess(int $tenantId): bool
    {
        return in_array($tenantId, self::accessibleIds(), true);
    }

    /** Only worth offering when there is more than one to consolidate. */
    public static function canSeeAll(): bool
    {
        return self::accessible()->count() > 1;
    }

    /**
     * Switch the tenant in context.
     *
     * Persisted on the account rather than in the session, so a Super Admin
     * returning tomorrow lands back where they were.
     *
     * @param  int|null  $tenantId  null selects the all-tenants view
     */
    public static function set(?int $tenantId): bool
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return false;
        }

        if ($tenantId !== null && ! self::canAccess($tenantId)) {
            return false;
        }

        if ($tenantId === null && ! self::canSeeAll()) {
            return false;
        }

        /*
         | The shop context belongs to the tenant that was just left, so it
         | goes with it - the stored pointer as well as the memo.
         |
         | Clearing only the memo would leave current_shop_id naming another
         | company's branch. CurrentShop::resolve() re-checks it against what
         | is reachable and would ignore it, so nothing leaks either way; but
         | a column that says the user is standing somewhere they are not is
         | the sort of thing that reads as a bug for years. Both are written
         | in the one save, so a failure cannot leave them disagreeing.
         |
         | all_shops_view goes back to false with it: "never chose" is the
         | honest state for a company just walked into, and it lands the user
         | on that company's default branch rather than in a consolidated
         | view they never asked for.
         */
        $user->forceFill([
            'current_tenant_id' => $tenantId,
            'current_shop_id' => null,
            'all_shops_view' => false,
        ])->save();

        self::$tenantId = $tenantId;
        self::$resolved = true;

        CurrentShop::forget();

        return true;
    }

    /**
     * Forget the memoised answer.
     *
     * Needed after a sign-in, after tenant assignment changes, and between
     * tests.
     */
    public static function forget(): void
    {
        self::$resolved = false;
        self::$tenantId = null;
        self::$accessible = null;
    }

    /**
     * Work out the tenant in context from the account.
     *
     * Re-checked against accessible() every request: moving a user to
     * another tenant has to take effect immediately, not the next time they
     * happen to switch.
     */
    private static function resolve(): void
    {
        $user = Auth::user();

        /*
         | Left unresolved on purpose - see accessible() for the reasoning.
         | "Nobody is signed in" has to stay a question that gets asked again,
         | because the answer changes the moment somebody signs in, and this
         | is memoised for the life of the process rather than the request.
         */
        if (! $user instanceof User) {
            self::$tenantId = null;

            return;
        }

        self::$resolved = true;

        // Ordinary staff are pinned to their own tenant. The stored choice
        // is irrelevant to them, and honouring it would be a way out.
        if (! $user->isSuperAdmin()) {
            if ($user->tenant_id !== null) {
                self::$tenantId = (int) $user->tenant_id;

                return;
            }

            /*
             | An account with no company on a single-company install.
             |
             | On an install that has exactly one business, "no company" and
             | "the only company" are not different answers - there is nothing
             | else it could mean. Accounts that predate the tenants table are
             | in this state until TenantSeeder adopts them, and so is anybody
             | created by a script that never had a company to name.
             |
             | The moment a second business exists the fallback stops, this
             | resolves to null again, and CurrentShop hands them nothing. That
             | is the safe direction to fail: an unassigned account loses access
             | rather than gaining a choice of whose data to read.
             */
            self::$tenantId = self::soleId();

            return;
        }

        $accessible = self::accessible();

        if ($accessible->isEmpty()) {
            self::$tenantId = null;

            return;
        }

        $stored = $user->current_tenant_id;

        if ($stored !== null && $accessible->contains('id', (int) $stored)) {
            self::$tenantId = (int) $stored;

            return;
        }

        /*
         | A Super Admin who has never chosen sits in the consolidated view
         | when there is more than one business to consolidate, and inside
         | the only one when there is not - which is what a single-tenant
         | install should feel like: no tenant concept at all.
         */
        self::$tenantId = $accessible->count() === 1 ? $accessible->first()->id : null;
    }
}
