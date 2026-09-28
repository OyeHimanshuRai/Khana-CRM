<?php

namespace App\Http\Controllers\Api\Machine;

use App\Models\Shop;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Khana\Kit\Sso\MachineToken;
use Khana\Kit\Support\Ulid;
use Khana\Kit\Tenancy\Entitlements;

/**
 * Which outlets the service calling CRM's API may read about.
 *
 * The outlet and sales endpoints exist for ERP - its first menu sync and its
 * nightly check (contracts/api/crm.yaml) - so the answer is the same rule the
 * outbox follows (OnlyEntitledOutlets): an outlet that owns ERP. CRM never
 * sends ERP a CRM-only outlet's sales, and does not hand them over when asked
 * either.
 *
 * On top of that, a token bound to one outlet (the kit's MachineToken
 * carries outlet_id for a third-party key) reads that outlet and no other,
 * whatever the URL says.
 *
 * Refusals are plain JSON {message}, the shape the kit's machine middleware
 * already answers 401 and 403 with, whatever the caller's Accept header.
 */
final class ServedOutlets
{
    public function __construct(private readonly Entitlements $entitlements) {}

    /** The live outlet a URL names, once the caller may read it. */
    public function resolve(Request $request, string $ulid): Shop
    {
        $shop = Ulid::isValid($ulid) ? Shop::query()->where('ulid', $ulid)->first() : null;

        if ($shop === null) {
            self::refuse(404, 'No such outlet.');
        }

        $this->authorise($request, $shop);

        return $shop;
    }

    public function authorise(Request $request, Shop $shop): void
    {
        $token = $request->attributes->get('khana.machine');

        if ($token instanceof MachineToken && $token->outletId !== null && $token->outletId !== $shop->ulid) {
            self::refuse(403, 'This token is for another outlet.');
        }

        if (! $this->entitlements->owns((string) $shop->ulid, 'erp')) {
            self::refuse(403, 'This outlet does not use Khana ERP, so CRM serves nothing about it here.');
        }
    }

    public static function refuse(int $status, string $message): never
    {
        throw new HttpResponseException(response()->json(['message' => $message], $status));
    }
}
