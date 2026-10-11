<?php

namespace App\Http\Controllers\Api;

use App\Models\Tenant;
use App\Services\Snapshot;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/tenants/{ulid}/snapshot - the reference data a product copies
 * for one business (contracts/api/accounts.yaml).
 *
 * Guarded by the kit's `machine:tenants:read` middleware: a token Accounts
 * signed and the product holds, carrying that one scope. No person, no
 * session - a server calls this.
 */
final class SnapshotController
{
    public function __invoke(string $ulid, Snapshot $snapshot): JsonResponse
    {
        $tenant = Tenant::findByUlid($ulid);

        abort_if($tenant === null, 404, 'No such business.');

        return response()->json($snapshot->of($tenant));
    }
}
