<?php

namespace App\Http\Controllers\Api;

use App\Models\Outlet;
use App\Models\ProductAccess;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/me - who just signed in, their outlets, and their role in each
 * product (contracts/api/accounts.yaml, schema Me).
 *
 * This is the first call CRM and ERP make after the sign-in redirect: they
 * turn its answer into a local session. Accounts decides who the person is
 * and what they may reach; the product never second-guesses it.
 */
final class MeController
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->loadMissing('tenant', 'access.outlets');

        // Each product access, keyed, carrying the outlets it reaches now.
        $products = [];
        $reachable = collect();

        foreach ($user->access as $access) {
            /** @var ProductAccess $access */
            $outlets = $access->reachableOutlets();
            $reachable = $reachable->concat($outlets);

            $products[$access->product] = [
                'role_key' => $access->role_key,
                'outlet_ids' => $outlets->pluck('ulid')->values()->all(),
            ];
        }

        // An owner reaches every outlet of their business, even ones without
        // an explicit access row, so the switcher shows them all.
        if ($user->isOwner()) {
            $reachable = $reachable->concat(
                Outlet::query()->where('tenant_id', $user->tenant_id)->orderBy('name')->get()
            );
        }

        $outlets = $reachable
            ->unique('id')
            ->sortBy('name')
            ->map(fn (Outlet $outlet) => [
                'outlet_id' => $outlet->ulid,
                'name' => $outlet->name,
                'code' => $outlet->code,
            ])
            ->values()
            ->all();

        return response()->json([
            'user_id' => $user->ulid,
            'name' => $user->name,
            'email' => $user->email,
            'mobile' => $user->mobile,
            'tenant_id' => $user->tenant?->ulid,
            'platform_admin' => $user->isPlatformAdmin(),
            'outlets' => $outlets,
            'products' => (object) $products,
        ]);
    }
}
