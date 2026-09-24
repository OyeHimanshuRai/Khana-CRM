<?php

namespace App\Http\Requests\Shop;

use App\Models\Customer;
use App\Models\Shop;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A storefront sign-in: mobile or email, plus password, scoped to one shop.
 *
 * Mirrors App\Http\Requests\Admin\LoginRequest's shape (rate limiting,
 * field-specific errors) without the admin-only pieces - LoginHistory and
 * BlockedIp are staff-security features, not applicable to a customer
 * session.
 */
class LoginRequest extends FormRequest
{
    private const MAX_ATTEMPTS = 5;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'login' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * @throws ValidationException
     */
    public function authenticate(Shop $shop): Customer
    {
        $this->ensureIsNotRateLimited($shop);

        $login = trim((string) $this->string('login'));

        $customer = Customer::forShop($shop->id)
            ->where(fn ($q) => $q->where('mobile', $login)->orWhere('email', strtolower($login)))
            ->first();

        if (! $customer || ! $customer->password || ! Hash::check((string) $this->input('password'), $customer->password)) {
            RateLimiter::hit($this->throttleKey($shop));

            throw ValidationException::withMessages(['login' => 'These credentials do not match our records.']);
        }

        if (! $customer->is_active) {
            RateLimiter::hit($this->throttleKey($shop));

            throw ValidationException::withMessages(['login' => 'This account has been deactivated.']);
        }

        Auth::guard('customer')->login($customer, $this->boolean('remember'));

        RateLimiter::clear($this->throttleKey($shop));

        return $customer;
    }

    private function ensureIsNotRateLimited(Shop $shop): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey($shop), self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey($shop));

        throw ValidationException::withMessages([
            'login' => __('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    private function throttleKey(Shop $shop): string
    {
        return Str::transliterate(
            $shop->id.'|'.Str::lower((string) $this->string('login')).'|'.$this->ip()
        );
    }
}
