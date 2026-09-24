<?php

namespace App\Http\Requests\Admin;

use App\Models\Alert;
use App\Models\BlockedIp;
use App\Models\LoginHistory;
use App\Models\User;
use App\Services\AlertService;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Number of failed attempts allowed before the throttle kicks in.
     */
    private const MAX_ATTEMPTS = 5;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to log the request's credentials in as an administrator.
     *
     * The `is_admin` condition is folded into the credentials so that a
     * The address and the password are reported separately, and the error is
     * attached to the field that actually failed, so the form can highlight
     * the right input.
     *
     * Note: distinguishing "no such email" from "wrong password" confirms
     * which addresses have accounts. That is a deliberate product decision
     * here; the rate limiter above is what keeps it from being cheap to
     * enumerate in bulk.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $email = $this->string('email')->lower()->toString();
        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->failed('email', 'Email does not match our records.', 'unknown_email');
        }

        /*
         | Checked before the password, and reported as a plain sign-in
         | failure: telling a blocked address that it is blocked hands an
         | attacker a signal, and the block is already recorded server-side.
         |
         | The global case is caught earlier by EnsureIpNotBlocked; this
         | catches a block scoped to this one account, which could not be
         | evaluated until the email named it.
         */
        if (BlockedIp::blocks((string) $this->ip(), $user)) {
            $this->failed('email', 'Sign-in failed. Please contact your administrator.', 'ip_blocked', $user, LoginHistory::BLOCKED);
        }

        if (! Hash::check($this->string('password')->toString(), $user->password)) {
            $this->failed('password', 'Password is incorrect.', 'bad_password', $user);
        }

        if (! $user->is_active) {
            $this->failed('email', 'This account has been deactivated.', 'inactive', $user);
        }

        if (! $user->is_admin) {
            $this->failed('email', 'This account does not have administrator access.', 'no_admin_access', $user);
        }

        Auth::login($user, $this->boolean('remember'));

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Record the failed attempt and abort with a message on the given field.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    private function failed(
        string $field,
        string $message,
        ?string $reason = null,
        ?User $user = null,
        string $status = LoginHistory::FAILED,
    ): never {
        RateLimiter::hit($this->throttleKey());

        LoginHistory::record($status, $this->string('email')->toString(), $user, $reason, $this);

        throw ValidationException::withMessages([$field => $message]);
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));

        LoginHistory::record(
            LoginHistory::FAILED,
            $this->string('email')->toString(),
            null,
            'throttled',
            $this,
        );

        $this->raiseSecurityAlert();

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Tell the branch's administrators that an account is being hammered.
     *
     * SRS 15's "System/security event -> Admin", raised at the one moment it
     * is unambiguously worth raising: five failures in a row against a real
     * account from one address.
     *
     * Filed against the account's own branch. An email nobody has an account
     * for is skipped silently rather than filed somewhere arbitrary - an
     * alert on the wrong branch's bell is not a smaller mistake than no
     * alert, it is a different and more confusing one. LoginHistory has the
     * attempt either way, which is where a real investigation starts.
     *
     * Never allowed to break the sign-in path: the user is already being
     * turned away, and turning them away with a 500 helps nobody.
     */
    private function raiseSecurityAlert(): void
    {
        try {
            $email = $this->string('email')->lower()->toString();
            $user = User::where('email', $email)->first();
            $shop = $user?->shops()->first();

            if (! $shop) {
                return;
            }

            app(AlertService::class)->raise(
                shop: $shop,
                type: Alert::SECURITY,
                title: sprintf('Sign-in blocked after %d failed attempts', self::MAX_ATTEMPTS),
                // Per account, per address, per hour: a sustained attack
                // produces a running record, not one row per attempt.
                dedupeKey: sprintf('lockout:%d:%s:%s', $user->getKey(), $this->ip(), now()->format('Y-m-d-H')),
                body: sprintf('%s was locked out from %s.', $user->email, $this->ip()),
                link: '/admin/users/'.$user->getKey().'/security',
                reference: $user,
                // The permission that opens the screen the link points at, so
                // the alert is never a 403 dressed up as a notification.
                can: 'settings.sessions.view',
                level: 'danger',
                expiresAt: now()->addDays(7),
            );
        } catch (\Throwable) {
            // Logged by the framework's handler; the lockout still stands.
        }
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(
            Str::lower($this->string('email')->toString()).'|'.$this->ip()
        );
    }
}
