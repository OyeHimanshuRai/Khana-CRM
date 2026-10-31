<?php

namespace App\Http\Controllers\Shop\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shop\LoginRequest;
use App\Models\ActivityLog;
use App\Models\Shop;
use App\Services\CartService;
use App\Support\GuardSession;
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

        $target = $this->intendedWithin($request, $shop);

        if ($request->expectsJson()) {
            return json_success('Signed in.', [], $target);
        }

        return redirect()->to($target);
    }

    public function destroy(Request $request, Shop $shop): RedirectResponse
    {
        ActivityLog::record('customer.logout', 'Signed out', Auth::guard('customer')->user());

        Auth::guard('customer')->logout();

        // A member of staff signed in on the same browser stays signed in.
        GuardSession::end($request, otherGuard: 'web');

        return redirect()->route('shop.home', ['shop' => $shop])->with('status', 'You have been signed out.');
    }

    /**
     * Where a customer goes after signing in: the page they were sent away
     * from, if it is on this restaurant's storefront, else its home.
     *
     * The session's intended URL is shared with the staff guard. A back-office
     * page that bounced to a staff sign-in earlier in the same browser must
     * not become where a customer lands - nor another restaurant's shop.
     */
    private function intendedWithin(Request $request, Shop $shop): string
    {
        $home = route('shop.home', ['shop' => $shop]);
        $intended = (string) $request->session()->pull('url.intended', '');

        return $intended !== '' && ($intended === $home || str_starts_with($intended, $home.'/')) ? $intended : $home;
    }
}
