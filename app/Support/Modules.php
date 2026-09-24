<?php

namespace App\Support;

use App\Models\Shop;

/**
 * Which lines of business the shop in context actually runs.
 *
 * The tier that sits beside permissions rather than above or below them.
 * Both have to say yes before anything is reachable, and they answer
 * different questions:
 *
 *     module      does this shop do this kind of business at all?
 *     permission  may this person do it?
 *
 * The catalogue lives in config/modules.php. Nothing here invents a module,
 * and a permission no module claims is never gated - the dashboard, settings,
 * users and content are part of the platform, not a trade a shop opts into.
 *
 * Two answers are memoised per request because the sidebar asks once per menu
 * entry and the middleware asks once per request.
 */
final class Modules
{
    /** @var array<string, bool>|null */
    private static ?array $enabled = null;

    /** @var array<string, array<int, string>>|null */
    private static ?array $owners = null;

    /**
     * Every module a shop can be given, as configured.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function catalogue(): array
    {
        return (array) config('modules', []);
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::catalogue());
    }

    /**
     * The modules a brand-new shop starts with.
     *
     * @return array<int, string>
     */
    public static function defaults(): array
    {
        return array_keys(array_filter(
            self::catalogue(),
            fn (array $module) => ! empty($module['default']),
        ));
    }

    /**
     * Keep only the keys that are really modules.
     *
     * A stale key left over from a renamed module has to disappear rather
     * than sit in the column looking meaningful.
     *
     * @param  array<int, mixed>  $keys
     * @return array<int, string>
     */
    public static function sanitise(array $keys): array
    {
        return array_values(array_intersect(
            self::keys(),
            array_map('strval', $keys),
        ));
    }

    /**
     * Is this module switched on for the shop in context?
     *
     * In the consolidated "all shops" view it is the union across the shops
     * the reader may reach: a group-level manufacturing report is meaningful
     * as long as one branch manufactures, and narrowing to the intersection
     * would blank the consolidated view for any group whose branches differ -
     * which is every group worth consolidating.
     */
    public static function enabled(string $module): bool
    {
        return self::map()[$module] ?? false;
    }

    /**
     * Whether a permission is reachable at all in this shop.
     *
     * The permission may be a full name (`manufacturing.jobs.view`), a
     * submodule (`manufacturing.jobs`) or a whole module (`manufacturing`) -
     * the sidebar, the route guards and the dashboard each hold a different
     * one of those, and all three have to get the same answer.
     *
     * A permission no module claims is allowed. That is the important
     * default: this gate only ever narrows what a shop asked to narrow, and
     * a new permission added tomorrow cannot go dark because somebody forgot
     * to list it.
     */
    public static function allows(?string $permission): bool
    {
        if (blank($permission)) {
            return true;
        }

        $owners = self::ownersFor($permission);

        if ($owners === []) {
            return true;
        }

        foreach ($owners as $module) {
            if (self::enabled($module)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The modules that claim this permission, most specific prefix first.
     *
     * `manufacturing.jobs.view` is claimed by whichever module lists
     * `manufacturing.jobs`, and failing that whichever lists `manufacturing`.
     * A permission claimed at both levels answers to both.
     *
     * @return array<int, string>
     */
    public static function ownersFor(string $permission): array
    {
        $parts = explode('.', $permission);
        $owners = self::owners();

        // Longest prefix wins, so `manufacturing.jobs` is preferred over the
        // broader `manufacturing` when both are configured.
        for ($length = count($parts); $length > 0; $length--) {
            $prefix = implode('.', array_slice($parts, 0, $length));

            if (isset($owners[$prefix])) {
                return $owners[$prefix];
            }
        }

        return [];
    }

    /**
     * The modules the given shop runs, falling back to the configured
     * defaults when it has never been asked.
     *
     * Null and empty are different on purpose. A shop created before this
     * feature existed has null and gets everything - going dark on upgrade
     * would be the worst possible reading of "not configured". A shop that
     * was configured and had every box unticked has [] and gets nothing,
     * because that is what was asked for.
     *
     * @return array<int, string>
     */
    public static function forShop(Shop $shop): array
    {
        $modules = $shop->modules;

        $chosen = $modules === null ? self::keys() : self::sanitise($modules);

        /*
         | The plan is a ceiling over the shop's own choice (§21).
         |
         | Two separate questions, and both have to say yes:
         |
         |     plan    has this business bought this feature?
         |     shop    does this branch want it?
         |
         | The overlap is what the branch gets. Collapsing them would mean
         | either an upgrade silently switches on modules nobody asked for,
         | or a branch that unticked something quietly loses it from the
         | invoice.
         |
         | Null from PlanAccess means no ceiling - no subscription, an
         | unrestricted plan, or a Super Admin - and the shop's own answer
         | stands untouched. That is what keeps an install that predates
         | subscriptions working exactly as it did.
         |
         | Asked of the SHOP, not of its tenant. Subscriptions are sold per
         | outlet, so two branches of one business can sit on different
         | plans - and reading the tenant would hand this branch the one
         | next door's ceiling. PlanAccess still falls back to a tenant-wide
         | blanket row when this outlet has no subscription of its own.
         */
        $cap = PlanAccess::modulesForShop($shop->id);

        return $cap === null
            ? $chosen
            : array_values(array_intersect($chosen, $cap));
    }

    /**
     * Forget the memoised answers - after a shop switch, and between tests.
     *
     * The owner map goes too. Nothing can rewrite the catalogue inside a
     * request, but a test that overrides config('modules') would otherwise
     * be answered from a map built before the override.
     */
    public static function forget(): void
    {
        self::$enabled = null;
        self::$owners = null;

        // The plan is half the answer now, so it has to be forgotten with
        // the other half - a shop switch can cross a tenant boundary.
        PlanAccess::forget();

        /*
         | And the price lists, which are per branch and per moment. A memo
         | that outlived a shop switch would price one branch's menu with
         | another's happy hour.
         */
        PriceLists::forget();
    }

    /**
     * module => enabled, for the shop in context.
     *
     * @return array<string, bool>
     */
    private static function map(): array
    {
        if (self::$enabled !== null) {
            return self::$enabled;
        }

        $shop = CurrentShop::get();

        if ($shop !== null) {
            $on = self::forShop($shop);

            return self::$enabled = self::flags($on);
        }

        /*
         | No shop in context. Two very different cases:
         |
         |   console / queue / an account with no shop  -> everything, because
         |       a nightly sweep has to walk every branch and a gate here
         |       would silently skip the ones it cannot see.
         |
         |   the consolidated view -> the union of the reader's own shops.
         */
        $shops = CurrentShop::accessible();

        if ($shops->isEmpty()) {
            return self::$enabled = self::flags(self::keys());
        }

        $union = [];

        foreach ($shops as $branch) {
            foreach (self::forShop($branch) as $module) {
                $union[$module] = true;
            }
        }

        return self::$enabled = self::flags(array_keys($union));
    }

    /**
     * @param  array<int, string>  $on
     * @return array<string, bool>
     */
    private static function flags(array $on): array
    {
        $flags = [];

        foreach (self::keys() as $key) {
            $flags[$key] = in_array($key, $on, true);
        }

        return $flags;
    }

    /**
     * permission prefix => the modules that claim it.
     *
     * Built once from the catalogue and cached for the process, because it
     * is derived entirely from config and cannot change under a request.
     *
     * @return array<string, array<int, string>>
     */
    private static function owners(): array
    {
        if (self::$owners !== null) {
            return self::$owners;
        }

        $owners = [];

        foreach (self::catalogue() as $key => $module) {
            foreach ((array) ($module['permissions'] ?? []) as $prefix) {
                $owners[$prefix][] = $key;
            }
        }

        return self::$owners = $owners;
    }
}
