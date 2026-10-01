<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;

/**
 * The admin's navigation, read from config/menu.php.
 *
 * The sidebar is a short list of hubs - Floor, Billing, Menu, Customers... -
 * and each hub's screens are tabs across the top of the page rather than a
 * second level of sidebar. Any job is the hub, then the tab: two clicks, and
 * the sidebar stays short enough to read at a glance.
 *
 * Both the sidebar and the tab strip ask this class, so what a person can
 * see and where "you are here" points are decided once.
 */
class Navigation
{
    /**
     * The menu this person may see: hidden tabs dropped, a hub with no tab
     * left dropped with them, and a heading with no hub left dropped last.
     *
     * @return list<array<string, mixed>>
     */
    public static function menu(): array
    {
        return collect(config('menu'))
            ->map(function (array $group) {
                $items = collect($group['items'] ?? [])
                    ->map(fn (array $item) => self::visibleHub($item))
                    ->filter()
                    ->values();

                return [...$group, 'items' => $items->all()];
            })
            ->filter(fn (array $group) => ! empty($group['items']))
            ->values()
            ->all();
    }

    /**
     * The hub the current page belongs to, with only the tabs this person
     * may open - or null on a page outside every hub.
     *
     * @return array<string, mixed>|null
     */
    public static function currentHub(): ?array
    {
        foreach (self::menu() as $group) {
            foreach ($group['items'] as $item) {
                if (! empty($item['tabs']) && self::isActive($item)) {
                    return $item;
                }
            }
        }

        return null;
    }

    /**
     * Is this entry - or, for a hub, any of its tabs - the current page?
     *
     * `active` names the route patterns that light it up and defaults to the
     * entry's own route. An entry with `params` must match them too: every
     * report is one route with a different segment, and only the one open
     * should be lit.
     */
    public static function isActive(array $item): bool
    {
        foreach ($item['tabs'] ?? [] as $tab) {
            if (self::isActive($tab)) {
                return true;
            }
        }

        $patterns = array_filter((array) ($item['active'] ?? ($item['route'] ?? null)));

        foreach ($patterns as $pattern) {
            if (request()->routeIs($pattern) && self::paramsMatch($item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Where an entry goes, or null for one that is not built.
     *
     * A hub goes to its first tab this person may open. `accounts` is a path
     * in Khana Accounts rather than a route here.
     */
    public static function href(array $item): ?string
    {
        if (! empty($item['tabs'])) {
            return self::href($item['tabs'][0]);
        }

        if (isset($item['accounts'])) {
            return Accounts::url($item['accounts']);
        }

        if (empty($item['route']) || ! Route::has($item['route'])) {
            return null;
        }

        return route($item['route'], $item['params'] ?? []);
    }

    /**
     * An entry is visible when two separate things are true, and they answer
     * different questions:
     *
     *   module      does this shop do this kind of business at all?
     *   permission  may this person do it?
     *
     * Both, in that order - the cheaper shop-level answer first. An entry
     * that declares neither is platform chrome and always shows.
     *
     * An Accounts entry on an install that has not been told where Accounts
     * is has nowhere to go, and is hidden rather than drawn as "coming soon".
     *
     * This is presentation only. The same pair is enforced server-side by the
     * `module` and `permission` middleware, because a hidden link is not a
     * closed door.
     */
    public static function visible(array $item): bool
    {
        $can = $item['can'] ?? null;

        return (empty($can) || Modules::allows($can))
            && (empty($can) || auth()->user()?->can($can))
            && (! isset($item['accounts']) || Accounts::url($item['accounts']) !== null);
    }

    /** @return array<string, mixed>|null */
    private static function visibleHub(array $item): ?array
    {
        if (empty($item['tabs'])) {
            return self::visible($item) ? $item : null;
        }

        $tabs = array_values(array_filter($item['tabs'], [self::class, 'visible']));

        return $tabs === [] ? null : [...$item, 'tabs' => $tabs];
    }

    private static function paramsMatch(array $item): bool
    {
        foreach ($item['params'] ?? [] as $name => $value) {
            $current = request()->route($name);

            if ($current instanceof \Illuminate\Database\Eloquent\Model) {
                $current = $current->getRouteKey();
            }

            if ((string) $current !== (string) $value) {
                return false;
            }
        }

        return true;
    }
}
