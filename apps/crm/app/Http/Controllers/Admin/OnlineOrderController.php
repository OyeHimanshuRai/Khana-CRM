<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Services\OrderService;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Orders placed on a shop's storefront.
 *
 * Order carries BelongsToShop, so every query here is already narrowed to
 * what the signed-in staff member may see - unlike the storefront's own
 * controllers, which run under the `customer` guard where that scope does
 * not apply (see the Order model's docblock).
 */
class OnlineOrderController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    public function index(Request $request): View
    {
        $orders = $this->filtered($request)
            // The table comes with it, or the Channel column would be an
            // N+1 across the page.
            ->with(['customer:id,name,mobile', 'tableSession.table:id,code'])
            ->latest('placed_at')
            ->paginate(15)
            ->withQueryString();

        $data = [
            'orders' => $orders,
            'search' => $request->string('q')->toString(),
            'status' => $request->string('status')->toString(),
            'paymentStatus' => $request->string('payment_status')->toString(),
            'statuses' => Order::STATUSES,
            'type' => $request->string('type')->toString(),
            'types' => Order::TYPES,
        ];

        return $request->header('X-Fragment')
            ? view('admin.orders._list', $data)
            : view('admin.orders.index', $data);
    }

    private function filtered(Request $request): Builder
    {
        return Order::query()
            ->search($request->string('q')->toString())
            ->ofStatus($request->string('status')->toString())
            /*
             | Every channel writes to `orders` - see the dine-in migration -
             | so this screen is no longer only the storefront's. Unfiltered it
             | shows all four, which is §4's "Order History"; the filter is what
             | makes it answer a narrower question.
             */
            ->ofType($request->string('type')->toString())
            ->when($request->filled('payment_status'), fn (Builder $q) => $q->where('payment_status', $request->string('payment_status')->toString()));
    }

    public function show(Order $order): View
    {
        return view('admin.orders.show', [
            'order' => $order->load(['items', 'customer', 'coupon', 'invoice', 'confirmedBy', 'paidBy', 'cancelledBy', 'statusLogs.user:id,name']),
            'paymentMethods' => Payment::METHODS,
        ]);
    }

    public function print(Order $order): View
    {
        return view('admin.orders.print', ['order' => $order->load(['items', 'customer'])]);
    }

    public function confirm(Order $order): JsonResponse
    {
        try {
            $this->orders->confirm($order, Auth::user());
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        return ApiResponse::success("Order {$order->order_number} confirmed.", ['status' => $order->fresh()->status]);
    }

    public function markPaid(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', 'string', 'in:'.implode(',', array_keys(Payment::METHODS))],
            'transaction_ref' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $this->orders->markPaid($order, Auth::user(), $data);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        return ApiResponse::success("Order {$order->order_number} marked paid and invoiced.", [
            'status' => $order->fresh()->status,
            'payment_status' => $order->fresh()->payment_status,
        ]);
    }

    public function markDelivered(Order $order): JsonResponse
    {
        try {
            $this->orders->markDelivered($order);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        return ApiResponse::success("Order {$order->order_number} marked delivered.", ['status' => $order->fresh()->status]);
    }

    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'in:packing,shipped'],
        ]);

        try {
            $this->orders->updateStatus($order, $data['status']);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        return ApiResponse::success("Order {$order->order_number} updated.", ['status' => $order->fresh()->status]);
    }

    public function cancel(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:250']]);

        try {
            $this->orders->cancel($order, $data['reason'], Auth::user());
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        return ApiResponse::success("Order {$order->order_number} cancelled.", ['status' => $order->fresh()->status]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filename = 'orders-'.now()->format('Y-m-d-His').'.csv';
        $query = $this->filtered($request)->with('customer:id,name,mobile');

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'wb');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Order #', 'Date', 'Customer', 'Mobile', 'Status', 'Payment Method', 'Payment Status', 'Total']);

            $query->chunkById(500, function ($chunk) use ($handle) {
                foreach ($chunk as $order) {
                    fputcsv($handle, [
                        $order->order_number,
                        $order->placed_at?->format('Y-m-d H:i'),
                        $order->customer?->name,
                        $order->customer?->mobile,
                        $order->statusLabel(),
                        strtoupper($order->payment_method),
                        ucfirst($order->payment_status),
                        number_format((float) $order->grand_total, 2, '.', ''),
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

}
