<?php

namespace App\Http\Controllers\Shop\Auth;

use App\Http\Controllers\Controller;
use App\Mail\CustomerPasswordResetMail;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Shop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * "Forgot your password?" on one shop's storefront.
 *
 * A customer account belongs to one shop - the same email can be a customer
 * of two restaurants, as two separate accounts - so the shop is part of
 * every lookup the broker makes, and a reset at one shop never touches the
 * account at another. The answer to "send me a link" never says whether the
 * address has an account here.
 */
class PasswordResetController extends Controller
{
    private const BROKER = 'customers';

    private const SENT = 'If that address has an account here, a link to reset the password is on its way.';

    public function request(Shop $shop): View
    {
        return view('shop.auth.forgot-password');
    }

    public function email(Request $request, Shop $shop): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);
        $email = Str::lower(trim($data['email']));

        $customer = Customer::query()->withoutGlobalScopes()
            ->where('shop_id', $shop->id)->where('email', $email)->where('is_active', true)
            ->whereNotNull('password')
            ->first();

        if ($customer !== null) {
            Password::broker(self::BROKER)->sendResetLink(
                ['email' => $email, 'shop_id' => $shop->id],
                function (Customer $customer, string $token) use ($shop) {
                    Mail::to($customer->email, $customer->name)->send(new CustomerPasswordResetMail($customer, $shop, $token));
                    ActivityLog::record('customer.password_reset_requested', 'Asked for a password reset link', $customer);
                },
            );
        }

        return $request->expectsJson()
            ? json_success(self::SENT)
            : back()->with('status', self::SENT);
    }

    public function edit(Request $request, Shop $shop, string $token): View
    {
        return view('shop.auth.reset-password', [
            'token' => $token,
            'email' => $request->string('email')->toString(),
        ]);
    }

    public function update(Request $request, Shop $shop): JsonResponse|RedirectResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);

        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $status = Password::broker(self::BROKER)->reset(
            [...$request->only('email', 'password', 'password_confirmation', 'token'), 'shop_id' => $shop->id],
            function (Customer $customer, string $password) {
                $customer->forceFill([
                    'password' => \Illuminate\Support\Facades\Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                ActivityLog::record('customer.password_reset', 'Reset their password from an emailed link', $customer);
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            $message = __($status);

            return $request->expectsJson()
                ? json_error($message, ['email' => [$message]])
                : back()->withInput($request->only('email'))->withErrors(['email' => $message]);
        }

        $note = 'Password changed. Sign in with the new one.';

        return $request->expectsJson()
            ? json_success($note, [], route('shop.login', $shop))
            : redirect()->route('shop.login', $shop)->with('status', $note);
    }
}
