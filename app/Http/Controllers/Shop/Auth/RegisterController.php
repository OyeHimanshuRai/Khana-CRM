<?php

namespace App\Http\Controllers\Shop\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shop\RegisterRequest;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Shop;
use App\Services\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class RegisterController extends Controller
{
    public function __construct(private readonly CartService $cart) {}

    public function create(Shop $shop): View
    {
        return view('shop.auth.register');
    }

    public function store(RegisterRequest $request, Shop $shop): RedirectResponse|JsonResponse
    {
        $data = $request->validated();

        $customer = Customer::query()->create([
            'shop_id' => $shop->id,
            'code' => Customer::nextCode($shop->id),
            'name' => $data['name'],
            'mobile' => $data['mobile'] ?? null,
            'email' => isset($data['email']) ? strtolower($data['email']) : null,
            'password' => Hash::make($data['password']),
            'type' => 'retail',
            'is_active' => true,
        ]);

        Auth::guard('customer')->login($customer);

        $request->session()->regenerate();

        $this->cart->mergeIntoCustomer($shop, $customer, $request);

        ActivityLog::record('customer.registered', "{$customer->name} created a storefront account", $customer);

        $target = route('shop.home', ['shop' => $shop]);

        if ($request->expectsJson()) {
            return json_success('Account created.', [], $target);
        }

        return redirect()->to($target);
    }
}
