<?php

namespace App\Http\Controllers;

use App\Models\PaymentIntent;
use App\Models\TableSession;
use App\Services\PaymentIntentService;
use App\Services\Payments\GatewayManager;
use App\Services\TableBillService;
use App\Services\TableSessionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A guest paying for their table from their own phone (§11).
 *
 * The session comes from the guest's cookie and never from the request - the
 * same rule the rest of the table flow follows. A URL that carried a session
 * token would get screenshotted into a group chat, and whoever opened it would
 * be paying somebody else's bill.
 *
 * This is the one part of the guest's journey that needs JavaScript: every
 * provider's checkout is a script. Where it is unavailable - no keys, no JS -
 * the page says "pay at the counter" and means it.
 */
class TablePaymentController extends Controller
{
    public function __construct(
        private readonly PaymentIntentService $payments,
        private readonly GatewayManager $gateways,
        private readonly TableBillService $bills,
        private readonly TableSessionService $sessions,
    ) {}

    /**
     * Open a checkout for whatever this table still owes.
     */
    public function start(Request $request): JsonResponse
    {
        $session = $this->session($request);

        if ($session === null) {
            return ApiResponse::error('Please scan the code on your table again.', [], 419);
        }

        if (! $this->gateways->isLive()) {
            return ApiResponse::error('This restaurant takes payment at the counter.');
        }

        $owed = $this->bills->summary($session)['unbilled'];

        if ($owed <= 0) {
            return ApiResponse::error('There is nothing left to pay. Please ask a member of staff.');
        }

        try {
            $intent = $this->payments->open($session, $owed, $session->shop_id);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        return ApiResponse::success('', [
            'reference' => $intent->reference,
            'amount' => (float) $intent->amount,
            'checkout' => $this->payments->checkoutFor($intent),
            'confirm_url' => route('table.pay.confirm'),
        ]);
    }

    /**
     * The browser says it paid.
     *
     * Verified against a signature it cannot forge, then captured - and the
     * capture is idempotent, because the provider's webhook is racing this
     * request on every single payment.
     */
    public function confirm(Request $request): JsonResponse
    {
        $session = $this->session($request);

        if ($session === null) {
            return ApiResponse::error('Please scan the code on your table again.', [], 419);
        }

        $data = $request->validate([
            'reference' => ['required', 'string', 'max:64'],
            'razorpay_order_id' => ['nullable', 'string', 'max:120'],
            'razorpay_payment_id' => ['nullable', 'string', 'max:120'],
            'razorpay_signature' => ['nullable', 'string', 'max:255'],
        ]);

        /*
         | Found by reference *and* by this sitting. Without the second half a
         | guest could post another table's reference and have their own bill
         | settled by somebody else's payment.
         */
        $intent = PaymentIntent::allShops()
            ->where('reference', $data['reference'])
            ->where('payable_type', $session->getMorphClass())
            ->where('payable_id', $session->id)
            ->first();

        if ($intent === null) {
            return ApiResponse::error('That payment does not belong to this table.', [], 404);
        }

        try {
            $intent = $this->payments->confirmFromBrowser($intent, $data);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        return ApiResponse::success(
            sprintf('Paid ₹%s. Thank you!', number_format((float) $intent->amount, 2)),
            ['status' => $intent->status],
            route('table.orders'),
        );
    }

    /**
     * The guest's own sitting, from their cookie.
     *
     * Deliberately a copy of the rule in TableOrderController rather than a
     * shared helper: it is three lines, and the day somebody makes it
     * configurable is the day a session id can come from a request.
     */
    private function session(Request $request): ?TableSession
    {
        return $this->sessions->resolve(
            $request->session()->get(TableScanController::SESSION_KEY)
        );
    }
}
