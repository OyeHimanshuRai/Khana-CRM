<?php

namespace App\Http\Controllers\Shop\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shop\LoginRequest;
use App\Models\ActivityLog;
use App\Models\Shop;
use App\Services\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function __construct(private readonly CartService $cart) {}

    public function create(Shop $shop): View
    {
        return view('shop.auth.login');
    }

    public function store(LoginRequest $request, Shop $shop): RedirectResponse|JsonResponse
    {
        $customer = $request->authenticate($shop);

        $request->session()->regenerate();

        $this->cart->mergeIntoCustomer($shop, $customer, $request);

        ActivityLog::record('customer.login', "{$customer->name} signed in", $customer);

        $target = redirect()->intended(route('shop.home', ['shop' => $shop]))->getTargetUrl();

        if ($request->expectsJson()) {
            return json_success('Signed in.', [], $target);
        }

        return redirect()->to($target);
    }

    public function destroy(Request $request, Shop $shop): RedirectResponse
    {
        ActivityLog::record('customer.logout', 'Signed out', Auth::guard('customer')->user());

        Auth::guard('customer')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('shop.home', ['shop' => $shop])->with('status', 'You have been signed out.');
    }
}
