<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ScanProductRequest;
use App\Services\ScannerService;
use Illuminate\Http\JsonResponse;

/**
 * The one endpoint every scan resolves through.
 *
 * A USB scanner, a Bluetooth scanner and the mobile camera all end up
 * producing nothing more than a string - see public/assets/js/scanner.js,
 * which is the only caller of this route regardless of which of the three
 * produced the code. There is no camera-specific or hardware-specific logic
 * anywhere in this class, deliberately: teaching this endpoint about its
 * caller's input method would be exactly the duplication the module exists
 * to avoid.
 */
class ScannerController extends Controller
{
    public function __construct(private ScannerService $scanner)
    {
        //
    }

    /**
     * POST /admin/api/scanner/product
     *
     * Registered under /admin because this app has no separate token-based
     * API layer - every AJAX endpoint, this one included, rides the same
     * authenticated session and CSRF token as the page that calls it (see
     * routes/web.php). A bare /api/scanner/product would need Sanctum's
     * stateful-domain middleware to authenticate the same way, which this
     * install does not have configured.
     */
    public function product(ScanProductRequest $request): JsonResponse
    {
        if (! $this->scanner->isEnabled()) {
            return response()->json([
                'success' => false,
                'message' => 'Barcode scanning is turned off for this shop. Enable it in Settings > Scanner.',
            ], 423);
        }

        $product = $this->scanner->lookup($request->string('code')->toString());

        if (! $product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Product found',
            'product' => $product,
        ]);
    }
}
