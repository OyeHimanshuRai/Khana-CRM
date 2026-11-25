<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Reads config/permissions.php and turns it into the shapes the rest of the
 * app needs: a flat list of permission names for the seeder/sync command,
 * and a nested tree for the role permission matrix UI.
 */
final class PermissionRegistry
{
    /** Separator between module, sub-module and action in a permission name. */
    public const SEPARATOR = '.';

    /**
     * Build a permission name from its parts.
     */
    public static function name(string $module, string $submodule, string $action): string
    {
        return implode(self::SEPARATOR, [$module, $submodule, $action]);
    }

    /**
     * Every permission name defined in config, flat.
     *
     * @return Collection<int, string>
     */
    public static function names(): Collection
    {
        return collect(config('permissions.modules'))
            ->flatMap(function (array $module, string $moduleKey) {
                return collect($module['submodules'] ?? [])
                    ->flatMap(fn (array $sub, string $subKey) => collect($sub['actions'] ?? [])
                        ->map(fn (string $action) => self::name($moduleKey, $subKey, $action)));
            })
            ->values();
    }

    /**
     * The taxonomy as a render-ready tree.
     *
     * Each module carries its sub-modules; each sub-module carries its
     * actions with the resolved permission name and human label. The matrix
     * view walks this directly.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function tree(): Collection
    {
        $actions = config('permissions.actions');

        return collect(config('permissions.modules'))
            ->map(function (array $module, string $moduleKey) use ($actions) {
                $submodules = collect($module['submodules'] ?? [])
                    ->map(function (array $sub, string $subKey) use ($moduleKey, $actions) {
                        $items = collect($sub['actions'] ?? [])->map(fn (string $action) => [
                            'action' => $action,
                            'name' => self::name($moduleKey, $subKey, $action),
                            'label' => $actions[$action]['label'] ?? Str::headline($action),
                            'tone' => $actions[$action]['tone'] ?? 'default',
                        ])->values();

                        return [
                            'key' => $subKey,
                            'label' => $sub['label'] ?? $subKey,
                            'actions' => $items,
                            'names' => $items->pluck('name')->values(),
                        ];
                    })
                    ->values();

                return [
                    'key' => $moduleKey,
                    'label' => $module['label'] ?? $moduleKey,
                    'icon' => $module['icon'] ?? 'circle',
                    'submodules' => $submodules,
                    'names' => $submodules->flatMap(fn (array $s) => $s['names'])->values(),
                    'count' => $submodules->sum(fn (array $s) => $s['names']->count()),
                ];
            })
            ->values();
    }

    /**
     * The tree split in two, the way the roles screen shows it.
     *
     * `platform` holds the abilities only the platform's own people can ever use
     * (PlatformAbilities: plans, roles, landing-page content, every company's
     * figures, licenses...); `restaurant` holds everything a restaurant's staff
     * work with. A module that has both appears in both, with only its own
     * actions, and a module with nothing left is dropped.
     *
     * @return array{platform: Collection<int, array<string, mixed>>, restaurant: Collection<int, array<string, mixed>>}
     */
    public static function split(): array
    {
        $tree = self::tree();

        return [
            'platform' => self::only($tree, fn (string $name) => PlatformAbilities::isPlatformOnly($name)),
            'restaurant' => self::only($tree, fn (string $name) => ! PlatformAbilities::isPlatformOnly($name)),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $tree
     * @return Collection<int, array<string, mixed>>
     */
    private static function only(Collection $tree, callable $keep): Collection
    {
        return $tree
            ->map(function (array $module) use ($keep) {
                $submodules = $module['submodules']
                    ->map(function (array $sub) use ($keep) {
                        $actions = $sub['actions']->filter(fn (array $a) => $keep($a['name']))->values();

                        return $actions->isEmpty() ? null : [
                            'actions' => $actions,
                            'names' => $actions->pluck('name')->values(),
                        ] + $sub;
                    })
                    ->filter()
                    ->values();

                if ($submodules->isEmpty()) {
                    return null;
                }

                return [
                    'submodules' => $submodules,
                    'names' => $submodules->flatMap(fn (array $s) => $s['names'])->values(),
                    'count' => $submodules->sum(fn (array $s) => $s['names']->count()),
                ] + $module;
            })
            ->filter()
            ->values();
    }

    /**
     * Whether a permission name is still declared in config.
     *
     * Used by the sync command to spot rows left behind after a rename.
     */
    public static function isDefined(string $permission): bool
    {
        return self::names()->contains($permission);
    }

    /**
     * Total number of declared permissions.
     */
    public static function count(): int
    {
        return self::names()->count();
    }
}
