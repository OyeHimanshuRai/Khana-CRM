<?php

namespace App\Http\Controllers\Api;

use App\Support\Roles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Khana\Kit\Support\Ulid;

/**
 * ERP's roles, for the team screen in Accounts (contracts/api/erp.yaml).
 *
 * Every business gets the same presets today, so the business named in the
 * query changes nothing yet - it is checked all the same, because the day a
 * business can make its own roles the answer will depend on it.
 */
final class RoleController
{
    public function __invoke(Request $request): JsonResponse
    {
        if (! Ulid::isValid($request->query('tenant_id'))) {
            return response()->json(['message' => 'Say which business: ?tenant_id=<id>.'], 422);
        }

        return response()->json(['product' => 'erp', 'roles' => Roles::catalogue()]);
    }
}
