<?php

namespace App\Http\Controllers\Auth;

use App\Models\LoginRecord;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Sign in to Accounts - the one sign-in behind every product. A person types
 * an email or a mobile number; a confirmed password lets them in.
 *
 * Every attempt, good or bad, is written to the sign-in history, since this
 * is the one door both products open.
 */
final class LoginController
{
    public function show(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()
            ->where('email', $data['identifier'])
            ->orWhere('mobile', $data['identifier'])
            ->first();

        if (! $user || $user->password === null || ! Hash::check($data['password'], $user->password)) {
            $this->record($request, $user, 'failed', 'bad_credentials', $data['identifier']);

            throw ValidationException::withMessages([
                'identifier' => 'Those details do not match any account.',
            ]);
        }

        if (! $user->isActive()) {
            $this->record($request, $user, 'blocked', 'not_active', $data['identifier']);

            throw ValidationException::withMessages([
                'identifier' => 'This account is not active. Ask the business owner to re-enable it.',
            ]);
        }

        if ($user->tenant_id !== null && ! $user->tenant?->isActive()) {
            $this->record($request, $user, 'blocked', 'tenant_suspended', $data['identifier']);

            throw ValidationException::withMessages([
                'identifier' => 'This business is suspended. Please contact Restro One support.',
            ]);
        }

        Auth::login($user, remember: $request->boolean('remember'));
        $user->forceFill(['last_login_at' => now()])->save();
        $this->record($request, $user, 'success', null, $data['identifier']);

        $request->session()->regenerate();

        // Sign-in may have interrupted a jump to a product (the /oauth
        // authorize link). Laravel's intended-url handling sends them on.
        return redirect()->intended(route('home'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    // GET /sso/logout - CRM and ERP send people here on sign-out, so a click on "Sign in with Khana Accounts" asks for the password again.
    public function ssoLogout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $back = (string) $request->query('redirect');

        return $this->isOwnProduct($back) ? redirect()->away($back) : redirect()->route('login');
    }

    // Only back to one of our own apps, never to an address a link could point anywhere.
    private function isOwnProduct(string $url): bool
    {
        $target = parse_url($url);

        if (! isset($target['scheme'], $target['host'])) {
            return false;
        }

        foreach ([config('app.url'), config('khana.urls.crm'), config('khana.urls.erp')] as $base) {
            $allowed = parse_url((string) $base);

            if (isset($allowed['scheme'], $allowed['host'])
                && strcasecmp($allowed['scheme'], $target['scheme']) === 0
                && strcasecmp($allowed['host'], $target['host']) === 0
                && ($allowed['port'] ?? null) === ($target['port'] ?? null)
                && str_starts_with($target['path'] ?? '/', rtrim($allowed['path'] ?? '', '/').'/')) {
                return true;
            }
        }

        return false;
    }

    private function record(Request $request, ?User $user, string $status, ?string $reason, string $identifier): void
    {
        LoginRecord::create([
            'user_id' => $user?->id,
            'identifier' => $identifier,
            'status' => $status,
            'reason' => $reason,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
