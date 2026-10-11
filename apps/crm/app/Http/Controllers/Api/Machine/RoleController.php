<?php

namespace App\Http\Controllers\Api\Machine;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Khana\Kit\Support\Ulid;
use Spatie\Permission\Models\Role;

/**
 * GET /api/v1/roles?tenant_id=... - the roles a business can hand its people
 * in CRM, for the team screen in Accounts (operationId roles).
 *
 * A role's key is the `key` column Accounts hands back at sign-in, which
 * App\Auth\AccountsSignIn matches to find the rights. `preset` is true for
 * the six roles Accounts offers out of the box (RolePermissionSeeder::
 * ACCOUNTS_KEYS); every other one was made on CRM's Roles screen.
 *
 * Left out, on purpose:
 *
 *   Super Admin and the platform roles (RolePermissionSeeder::PLATFORM_ROLES)
 *     They carry rights across every business - platform settings, the
 *     marketing site. Listing them would let an owner in Accounts give one
 *     to their cashier.
 *   A role whose key is not a contract code (defs.json code)
 *     A key made from an old name that starts with a digit, say. Accounts
 *     could not store it, and CRM will not invent a different one that
 *     sign-in would then fail to match.
 *
 * CRM's roles are defined once for the whole install (config('permission.
 * teams') is off), so the tenant_id names the business asking but does not
 * narrow the list today.
 */
final class RoleController
{
    public function __invoke(Request $request): JsonResponse
    {
        if (! Ulid::isValid($request->query('tenant_id'))) {
            ServedOutlets::refuse(422, 'Send the business as ?tenant_id=<ULID>.');
        }

        $presets = array_values(RolePermissionSeeder::ACCOUNTS_KEYS);

        $roles = Role::query()
            ->where('guard_name', 'web')
            ->whereNotIn('name', [User::SUPER_ADMIN, ...RolePermissionSeeder::PLATFORM_ROLES])
            ->orderBy('name')
            ->get(['id', 'name', 'key'])
            ->filter(fn (Role $role) => preg_match('/^[a-z][a-z0-9_]{0,39}$/', (string) $role->key) === 1)
            ->map(fn (Role $role) => [
                'key' => $role->key,
                'name' => $role->name,
                'preset' => in_array($role->key, $presets, true),
            ])
            ->values();

        return response()->json([
            'product' => 'crm',
            'roles' => $roles,
        ]);
    }
}
