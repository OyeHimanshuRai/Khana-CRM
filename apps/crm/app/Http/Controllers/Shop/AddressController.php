<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\Shop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AddressController extends Controller
{
    public function index(Shop $shop): View
    {
        $customer = Auth::guard('customer')->user();
        $addresses = $customer->addresses()->where('shop_id', $shop->id)->get();

        return view('shop.account.addresses.index', compact('addresses'));
    }

    public function store(Request $request, Shop $shop): RedirectResponse|JsonResponse
    {
        $data = $this->validated($request);
        $customer = Auth::guard('customer')->user();

        if (! empty($data['is_default'])) {
            $customer->addresses()->where('shop_id', $shop->id)->update(['is_default' => false]);
        }

        $customer->addresses()->create($data + ['shop_id' => $shop->id]);

        if ($request->expectsJson()) {
            return json_success('Address added.', [], route('shop.account.addresses.index', $shop));
        }

        return back()->with('status', 'Address added.');
    }

    public function update(Request $request, Shop $shop, Address $address): RedirectResponse
    {
        $this->authoriseAddress($shop, $address);

        $data = $this->validated($request);
        $customer = Auth::guard('customer')->user();

        if (! empty($data['is_default'])) {
            $customer->addresses()->where('shop_id', $shop->id)->where('id', '!=', $address->id)->update(['is_default' => false]);
        }

        $address->update($data);

        return back()->with('status', 'Address updated.');
    }

    public function destroy(Shop $shop, Address $address): RedirectResponse
    {
        $this->authoriseAddress($shop, $address);

        $address->delete();

        return back()->with('status', 'Address removed.');
    }

    private function authoriseAddress(Shop $shop, Address $address): void
    {
        abort_unless(
            $address->shop_id === $shop->id && $address->customer_id === Auth::guard('customer')->id(),
            404
        );
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'label' => ['nullable', 'string', 'max:40'],
            'recipient_name' => ['required', 'string', 'max:150'],
            'mobile' => ['required', 'string', 'max:20'],
            'address_line1' => ['required', 'string', 'max:190'],
            'address_line2' => ['nullable', 'string', 'max:190'],
            'village' => ['nullable', 'string', 'max:120'],
            'taluka' => ['nullable', 'string', 'max:120'],
            'district' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:90'],
            'state' => ['nullable', 'string', 'max:90'],
            'pincode' => ['nullable', 'string', 'max:12'],
            'is_default' => ['nullable', 'boolean'],
        ]);
    }
}
