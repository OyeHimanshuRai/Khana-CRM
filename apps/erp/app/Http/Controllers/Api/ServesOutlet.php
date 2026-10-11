<?php

namespace App\Http\Controllers\Api;

use App\Support\Outlets;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Khana\Kit\Sso\MachineToken;
use Khana\Kit\Tenancy\Entitlements;

/**
 * The checks every API call about an outlet makes before it answers.
 *
 * The kit's `machine` middleware has already proved the token is one
 * Accounts issued and carries the right scope. What is left is ERP's own:
 * the outlet has to be one ERP knows, it has to own ERP, and a token bound
 * to one outlet - a third-party sales key - may only speak for that one.
 */
trait ServesOutlet
{
    /** The outlet's tenant, or a response that says why not. */
    protected function tenantOf(Request $request, string $outletId): string
    {
        $outlet = Outlets::find($outletId);

        if ($outlet === null) {
            throw new HttpResponseException(response()->json(['message' => 'ERP has not heard of that outlet.'], 404));
        }

        $token = $request->attributes->get('khana.machine');

        if ($token instanceof MachineToken && $token->outletId !== null && $token->outletId !== $outletId) {
            throw new HttpResponseException(response()->json(['message' => 'This key is for a different outlet.'], 403));
        }

        if (! app(Entitlements::class)->owns($outletId, 'erp')) {
            throw new HttpResponseException(response()->json(['message' => 'That outlet does not have Khana ERP.'], 403));
        }

        return $outlet->tenant_id;
    }
}
