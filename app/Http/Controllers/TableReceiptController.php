<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PaymentIntent;
use App\Models\Setting;
use App\Models\TableSession;
use App\Services\TableSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Contracts\View\View;

/**
 * The digital receipt a guest lands on the moment the table is paid.
 *
 * Same rule as the rest of the guest's journey: the sitting comes from their
 * own cookie, never from the URL, so a receipt link screenshotted into a group
 * chat opens nothing for whoever receives it.
 */
class TableReceiptController extends Controller
{
    public function __construct(private readonly TableSessionService $sessions) {}

    public function show(Request $request): View|RedirectResponse
    {
        $session = $this->session($request);

        if ($session === null) {
            return redirect()->route('table.expired');
        }

        return view('table.receipt', $this->data($session));
    }

    /**
     * The same receipt as one self-contained file: opens on any phone,
     * needs no connection and carries its own styling.
     */
    public function download(Request $request): Response|RedirectResponse
    {
        $session = $this->session($request);

        if ($session === null) {
            return redirect()->route('table.expired');
        }

        $data = $this->data($session);
        $name = 'receipt-'.preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($data['invoices']->first()?->number ?? 'table')).'.html';

        return response(view('table.receipt-download', $data)->render(), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function data(TableSession $session): array
    {
        $invoices = $session->invoices()
            ->with(['items', 'payments'])
            ->get()
            ->reject(fn ($invoice) => $invoice->isCancelled())
            ->values();

        $grand = round((float) $invoices->sum('grand_total'), 2);
        $paid = round((float) $invoices->sum('paid_total'), 2);

        $online = PaymentIntent::allShops()
            ->where('payable_type', $session->getMorphClass())
            ->where('payable_id', $session->id)
            ->where('status', PaymentIntent::PAID)
            ->latest('id')
            ->first();

        $modes = $invoices->flatMap(fn ($invoice) => $invoice->payments)
            ->filter(fn ($payment) => $payment->isEffective())
            ->map(fn ($payment) => $payment->methodLabel())
            ->unique()
            ->values();

        $shop = $session->table?->shop;

        return [
            'session' => $session,
            'table' => $session->table,
            'shop' => $shop,
            'company' => Setting::get('company_name', config('app.name')),
            'invoices' => $invoices,
            'orderNumbers' => $session->orders()
                ->where('status', '!=', Order::CANCELLED)
                ->orderBy('id')
                ->pluck('order_number'),
            'grandTotal' => $grand,
            'paidTotal' => $paid,
            'isPaid' => $invoices->isNotEmpty() && $paid + 0.009 >= $grand,
            'paymentMode' => $online !== null
                ? 'Online (UPI / Card)'
                : ($modes->isEmpty() ? '—' : $modes->implode(', ')),
            'transactionId' => $online?->provider_payment_id,
            'paidAt' => $online?->paid_at ?? $invoices->last()?->invoiced_at,
        ];
    }

    private function session(Request $request): ?TableSession
    {
        return $this->sessions->resolveForReceipt(
            $request->session()->get(TableScanController::SESSION_KEY)
        );
    }
}
