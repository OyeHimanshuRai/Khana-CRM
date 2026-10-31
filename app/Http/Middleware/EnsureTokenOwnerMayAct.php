<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\ApiAbilities;
use App\Support\ApiResponse;
use App\Support\Modules;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTokenOwnerMayAct
{
    /**
     * An API call is allowed only while the person behind the token still may.
     *
     * Always: the owner is an active administrator. A token is the owner's
     * signature, and a deactivated leaver's signature is worth nothing.
     *
     * With an ability (`api.user:orders:read`): the token holds it, the owner
     * still holds the permission it stands for (ApiAbilities), and the branch
     * runs the module that permission belongs to. A route may name a stricter
     * permission than the ability's own as a second argument - moving an
     * order along is `sales.orders.edit`, not `create`.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $ability = null, ?string $permission = null): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->is_admin || ! $user->is_active) {
            return ApiResponse::error('The account this token belongs to can no longer use the API.', [], 403);
        }

        if ($ability === null) {
            return $next($request);
        }

        $token = $user->currentAccessToken();

        if ($token === null || ! $user->tokenCan($ability)) {
            return ApiResponse::error("This token does not have the {$ability} ability.", [], 403);
        }

        $permission ??= ApiAbilities::permissionFor($ability);

        if ($permission === null || ! $user->can($permission)) {
            return ApiResponse::error("The account this token belongs to may no longer do that ({$ability}).", [], 403);
        }

        if (! Modules::allows($permission)) {
            return ApiResponse::error('That is not switched on for this branch.', [], 403);
        }

        return $next($request);
    }
}
