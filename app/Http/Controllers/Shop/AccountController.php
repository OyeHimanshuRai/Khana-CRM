<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function edit(Shop $shop): View
    {
        $customer = Auth::guard('customer')->user();

        return view('shop.account.edit', compact('customer'));
    }

    public function update(Request $request, Shop $shop): RedirectResponse|JsonResponse
    {
        $customer = Auth::guard('customer')->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'mobile' => [
                'nullable', 'string', 'max:20',
                Rule::unique('customers', 'mobile')->where('shop_id', $shop->id)->ignore($customer->id),
            ],
            'email' => [
                'nullable', 'string', 'email', 'max:150',
                Rule::unique('customers', 'email')->where('shop_id', $shop->id)->ignore($customer->id),
            ],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ]);

        if (! empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $customer->update($data);

        if ($request->expectsJson()) {
            return json_success('Account updated.');
        }

        return back()->with('status', 'Account updated.');
    }
}
