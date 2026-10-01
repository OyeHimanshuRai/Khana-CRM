<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;

/**
 * Hubs and their tabs, read from config/menu.php.
 *
 * The kit draws the sidebar; this answers the two questions the kit cannot:
 * which of a hub's tabs this person may open, and which hub and tab the
 * current page belongs to.
 */
final class Navigation
{
    /**
     * The hub the current page belongs to, with only the tabs this person
     * may open - or null on a page outside every hub.
     *
     * @return array<string, mixed>|null
     */
    public static function currentHub(): ?array
    {
        foreach (config('menu', []) as $group) {
            foreach ($group['items'] ?? [] as $item) {
                if (empty($item['tabs'])) {
                    continue;
                }

                $tabs = self::visibleTabs($item);

                foreach ($item['tabs'] as $tab) {
                    if (self::isActive($tab)) {
                        return [...$item, 'tabs' => $tabs];
                    }
                }
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    public static function visibleTabs(array $hub): array
    {
        $user = auth()->user();

        return array_values(array_filter(
            $hub['tabs'] ?? [],
            fn (array $tab) => empty($tab['can']) || $user?->can($tab['can']),
        ));
    }

    /**
     * Is this tab the current page? A tab with `params` must match them
     * too: every report is one route with a different segment.
     */
    public static function isActive(array $tab): bool
    {
        foreach (array_filter((array) ($tab['active'] ?? ($tab['route'] ?? null))) as $pattern) {
            if (! request()->routeIs($pattern)) {
                continue;
            }

            foreach ($tab['params'] ?? [] as $name => $value) {
                if ((string) request()->route($name) !== (string) $value) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
    }

    public static function href(array $tab): ?string
    {
        return ! empty($tab['route']) && Route::has($tab['route'])
            ? route($tab['route'], $tab['params'] ?? [])
            : null;
    }
}
