<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Nobody can hand out more than they hold.
 *
 * Roles are defined once for the whole platform, and some of what they carry
 * crosses the company line: Super Admin and Admin reach every business, and
 * so does any `.manage` right (support.tickets.manage opens every
 * restaurant's support threads). RolePermissionSeeder keeps those out of a
 * Tenant Owner's own role - this keeps them out of everything a Tenant Owner
 * can give, which is the door that was open: the user screens accepted any
 * role and any permission that existed.
 *
 * Only what is being added is checked. Editing the name of a colleague who
 * already holds something the editor could not grant is fine; granting it
 * again is not needed and is not asked for.
 */
final class GrantGuard
{
    /**
     * The roles this person may give: each one made only of rights they hold
     * themselves, and never Super Admin unless they are one.
     *
     * @return Collection<int, Role>
     */
    public static function assignableRoles(User $actor): Collection
    {
        $roles = Role::query()->with('permissions')->orderBy('name')->get();

        if ($actor->isSuperAdmin()) {
            return $roles;
        }

        $held = self::held($actor);

        return $roles
            ->filter(fn (Role $role) => $role->name !== User::SUPER_ADMIN
                && $role->permissions->every(fn ($permission) => isset($held[$permission->name])
                    && ! self::isPlatformOnly($permission->name)))
            ->values();
    }

    /**
     * @param  array<int, string>  $requested  the roles the form asks for
     * @param  array<int, string>  $existing  the roles the account already has
     *
     * @throws ValidationException
     */
    public static function assertCanGiveRoles(User $actor, array $requested, array $existing = []): void
    {
        if ($actor->isSuperAdmin()) {
            return;
        }

        $allowed = self::assignableRoles($actor)->pluck('name')->all();
        $refused = array_values(array_diff(array_diff($requested, $existing), $allowed));

        if ($refused !== []) {
            throw ValidationException::withMessages([
                'roles' => 'You can only give a role whose rights you hold yourself. Not allowed: '.implode(', ', $refused).'.',
            ]);
        }
    }

    /**
     * @param  array<int, string>  $requested
     * @param  array<int, string>  $existing
     *
     * @throws ValidationException
     */
    public static function assertCanGivePermissions(User $actor, array $requested, array $existing = []): void
    {
        if ($actor->isSuperAdmin()) {
            return;
        }

        $held = self::held($actor);

        $refused = collect(array_diff($requested, $existing))
            ->filter(fn (string $permission) => ! isset($held[$permission]) || self::isPlatformOnly($permission))
            ->values()
            ->all();

        if ($refused !== []) {
            throw ValidationException::withMessages([
                'permissions' => 'You can only give rights you hold yourself. Not allowed: '.implode(', ', $refused).'.',
            ]);
        }
    }

    /** A right that crosses the company boundary - the platform's alone to give. */
    public static function isPlatformOnly(string $permission): bool
    {
        return str_ends_with($permission, '.manage');
    }

    /**
     * @return array<string, int>
     */
    private static function held(User $actor): array
    {
        return $actor->getAllPermissions()->pluck('name')->flip()->all();
    }
}
