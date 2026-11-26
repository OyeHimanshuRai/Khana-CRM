<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Services\Payments\GatewayManager;
use App\Support\ApiResponse;
use App\Support\CurrentShop;
use App\Support\Modules;
use App\Support\OnlineBooking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

// The outlet's rules for guests booking a table on its storefront.
class OnlineBookingController extends Controller
{
    public function edit(GatewayManager $gateways): View
    {
        $shop = CurrentShop::get();

        return view('admin.reservations.online', [
            'shop' => $shop,
            'settings' => $shop ? OnlineBooking::settings($shop) : OnlineBooking::DEFAULTS,
            'onlinePayment' => $gateways->isLive(),
            'storefront' => $shop ? in_array('storefront', Modules::forShop($shop), true) : false,
            'bookUrl' => $shop && filled($shop->slug) ? route('shop.book', $shop) : null,
        ]);
    }

    public function update(Request $request, GatewayManager $gateways): JsonResponse
    {
        $shop = CurrentShop::get();
        if (! $shop instanceof Shop) {
            return ApiResponse::error('Choose a branch first: online booking is set per branch.');
        }

        $data = $request->validate(OnlineBooking::rules(), [
            'closes.after' => 'The last booking time must be after the first.',
            'deposit_amount.required_unless' => 'Enter the advance amount.',
        ]);
        $settings = OnlineBooking::normalise($data);

        if ($settings['enabled'] && $settings['deposit_mode'] !== OnlineBooking::DEPOSIT_NONE && $settings['deposit_amount'] <= 0) {
            return ApiResponse::error('Enter the advance amount, or choose "No advance".', ['deposit_amount' => ['Enter the advance amount.']]);
        }
        if ($settings['enabled'] && $settings['deposit_mode'] !== OnlineBooking::DEPOSIT_NONE && ! $gateways->isLive()) {
            return ApiResponse::error('Online payment is not set up, so an advance cannot be collected. Set up Online Payment first, or choose "No advance".');
        }

        $shop->forceFill(['online_booking' => $settings])->save();

        return ApiResponse::success($settings['enabled'] ? 'Online booking is on.' : 'Online booking settings saved (booking is off).');
    }
}
