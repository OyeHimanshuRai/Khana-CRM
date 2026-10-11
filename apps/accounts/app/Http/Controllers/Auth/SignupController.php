<?php

namespace App\Http\Controllers\Auth;

use App\Models\Outlet;
use App\Models\Plan;
use App\Services\Signup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * A restaurant's first minute: the business, its first outlet, the owner and
 * a trial, in one submission. Kept to one screen on purpose - a longer form
 * here is a shop that never opens.
 */
final class SignupController
{
    public function show(): View
    {
        return view('auth.signup', [
            'plans' => Plan::active()->get(),
            'states' => Outlet::STATES,
        ]);
    }

    public function register(Request $request, Signup $signup): RedirectResponse
    {
        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:255'],
            'outlet_name' => ['required', 'string', 'max:255'],
            'outlet_code' => ['required', 'string', 'max:12'],
            'city' => ['required', 'string', 'max:100'],
            'state_code' => ['required', Rule::in(array_keys(Outlet::STATES))],
            'owner_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'mobile' => ['nullable', 'string', 'max:16', 'unique:users,mobile'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'plan' => ['required', Rule::exists('plans', 'code')->where('is_active', true)],
            'period' => ['required', Rule::in(Plan::PERIODS)],
        ]);

        $plan = Plan::where('code', $data['plan'])->firstOrFail();

        $owner = $signup->register(
            business: ['name' => $data['business_name']],
            outlet: [
                'name' => $data['outlet_name'],
                'code' => strtoupper($data['outlet_code']),
                'city' => $data['city'],
                'state_code' => $data['state_code'],
                'address_line1' => $data['outlet_name'],
            ],
            owner: [
                'name' => $data['owner_name'],
                'email' => $data['email'],
                'mobile' => $data['mobile'] ?? null,
                'password' => $data['password'],
            ],
            plan: $plan,
            period: $data['period'],
        );

        Auth::login($owner);
        $request->session()->regenerate();

        return redirect()->route('home')->with('status', 'Welcome to Khana. Your trial has started.');
    }
}
