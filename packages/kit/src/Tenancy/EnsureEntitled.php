<?php

namespace Khana\Kit\Tenancy;

use Closure;
use Illuminate\Http\Request;
use Khana\Kit\Support\ApiResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * `entitled:crm` / `entitled:erp` - may this outlet use this product at all?
 *
 * The product-level gate, above permissions (may this person do it) and above
 * modules (does this outlet run this line of business). All three must say
 * yes.
 *
 *  - Bought, on trial or in grace: through.
 *  - Suspended for non-payment: read and export only. Looking is allowed,
 *    changing is not.
 *  - Never bought, or ended: refused, with a sentence that says which.
 *
 * In the consolidated view the product is usable if any reachable outlet has
 * it. Platform staff are never refused: the reason to open a lapsed outlet is
 * to fix why it lapsed.
 */
final class EnsureEntitled
{
    public function __construct(
        private readonly Context $context,
        private readonly Entitlements $entitlements,
    ) {}

    public function handle(Request $request, Closure $next, string $product): Response
    {
        $actor = $this->context->actor();

        if ($actor?->isPlatformAdmin()) {
            return $next($request);
        }

        $outlet = $this->context->outletId();
        $outlets = $outlet !== null ? [$outlet] : $this->context->accessibleOutlets();

        $usable = false;
        $held = false;

        foreach ($outlets as $candidate) {
            $usable = $usable || $this->entitlements->allows($candidate, $product);
            $held = $held || $this->entitlements->owns($candidate, $product);
        }

        if ($usable || ($held && $request->isMethodSafe())) {
            return $next($request);
        }

        $name = 'Khana '.strtoupper($product);

        $message = $held
            ? "This outlet's {$name} subscription is suspended: you can view and export, but not change anything until it is renewed."
            : "This outlet has not bought {$name}.";

        if ($request->expectsJson()) {
            return ApiResponse::error($message, [], 403);
        }

        abort(403, $message);
    }
}
