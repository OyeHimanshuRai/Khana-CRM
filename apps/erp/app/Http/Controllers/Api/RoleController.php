<?php

namespace App\Http\Controllers\Api;

use App\Support\Roles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Khana\Kit\Support\Ulid;

/**
 * ERP's roles, for the team screen in Accounts (contracts/api/erp.yaml).
 *
 * The presets are the same for every business; the ones a business made for
 * itself are its own, so the business named in the query decides the rest
 * of the list. `preset` is false for those.
 */
final class RoleController
{
    public function __invoke(Request $request): JsonResponse
    {
        $tenant = $request->query('tenant_id');

        if (! Ulid::isValid($tenant)) {
            return response()->json(['message' => 'Say which business: ?tenant_id=<id>.'], 422);
        }

        return response()->json(['product' => 'erp', 'roles' => Roles::catalogue($tenant)]);
    }
}
