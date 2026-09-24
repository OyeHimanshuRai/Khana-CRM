<?php
namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Services\CartService;
use App\Services\CouponService;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use RuntimeException;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly CartService $cart,
        private readonly CouponService $coupons,
        private readonly OrderService $orders,
    ) {}

    public function index(Request $request, Shop $shop): View|RedirectResponse
    {
        $customer = Auth::guard('customer')->user();
        $cart = $this->cart->contents($shop, $customer, $request);

        if ($cart['items']->isEmpty()) {
            return redirect()->route('shop.cart', ['shop' => $shop])->with('error', 'Your cart is empty.');
        }

        $addresses = $customer->addresses()->where('shop_id', $shop->id)->get();

        return view('shop.checkout.index', compact('cart', 'addresses'));
    }

    public function applyCoupon(Request $request, Shop $shop): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:40']]);
        $customer = Auth::guard('customer')->user();
        $cart = $this->cart->contents($shop, $customer, $request);

        try {
            $coupon = $this->coupons->validate($shop, trim($data['code']), $customer, $cart['subtotal']);
            $discount = $this->coupons->discountFor($coupon, $cart['subtotal']);
        } catch (RuntimeException $e) {
            return json_error($e->getMessage());
        }

        return json_success('Coupon applied.', [
            'discount' => $discount,
            'grand_total' => round($cart['subtotal'] - $discount, 2),
        ]);
    }

    public function store(Request $request, Shop $shop): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'address_id' => ['nullable', 'integer'],
            'recipient_name' => ['required_without:address_id', 'nullable', 'string', 'max:150'],
            'mobile' => ['required_without:address_id', 'nullable', 'string', 'max:20'],
            'address_line1' => ['required_without:address_id', 'nullable', 'string', 'max:190'],
            'address_line2' => ['nullable', 'string', 'max:190'],
            'city' => ['nullable', 'string', 'max:90'],
            'state' => ['nullable', 'string', 'max:90'],
            'pincode' => ['nullable', 'string', 'max:12'],
            'save_address' => ['nullable', 'boolean'],
            'payment_method' => ['required', 'in:cod,online'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
            'customer_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $customer = Auth::guard('customer')->user();

        if (empty($data['address_id']) && ! empty($data['save_address'])) {
            $address = $customer->addresses()->create([
                'shop_id' => $shop->id,
                'recipient_name' => $data['recipient_name'],
                'mobile' => $data['mobile'],
                'address_line1' => $data['address_line1'],
                'address_line2' => $data['address_line2'] ?? null,
                'city' => $data['city'] ?? null,
                'state' => $data['state'] ?? null,
                'pincode' => $data['pincode'] ?? null,
                'is_default' => $customer->addresses()->where('shop_id', $shop->id)->doesntExist(),
            ]);

            $data['address_id'] = $address->id;
        }

        try {
            $order = $this->orders->place($shop, $customer, $request, [
                'address_id' => $data['address_id'] ?? null,
                'address' => empty($data['address_id']) ? $data : null,
                'payment_method' => $data['payment_method'],
                'coupon_code' => $data['coupon_code'] ?? null,
                'customer_note' => $data['customer_note'] ?? null,
            ]);
        } catch (RuntimeException $e) {
            /*
             | A refusal here is an ordinary one - a coupon that expired while
             | the page was open, a dish that sold out - and it arrives after
             | the shopper has typed an address. Reloading the page to say so
             | is what made them type it twice.
             */
            if ($request->expectsJson()) {
                return json_error($e->getMessage());
            }

            return back()->withInput()->with('error', $e->getMessage());
        }

        $url = route('shop.orders.show', ['shop' => $shop, 'order' => $order->id]);

        if ($request->expectsJson()) {
            return json_success('Order placed.', ['order_id' => $order->id], $url);
        }

        return redirect($url)->with('status', 'Order placed.');
    }
}
