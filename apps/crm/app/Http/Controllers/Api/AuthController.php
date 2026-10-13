<?php

namespace App\Http\Controllers\Api;

use App\Auth\AccountsSignIn;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LoginRequest;
use App\Models\ActivityLog;
use App\Models\LoginHistory;
use App\Models\User;
use App\Support\SignInMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Khana\Kit\Sso\SignedInPerson;
use Khana\Kit\Sso\SignInFailed;
use Throwable;

// App sign-in: Accounts token or local password -> Sanctum token with abilities from the person's role.
class AuthController extends Controller
{
    public const TOKEN_DAYS = 30;

    // Ability => any one of these permissions earns it.
    public const ABILITY_PERMISSIONS = [
        'menu:read' => ['inventory.products.view', 'pos.tables.order'],
        'menu:write' => ['inventory.products.edit'],
        'tables:read' => ['dining.tables.view', 'pos.tables.view'],
        'orders:read' => ['sales.orders.view', 'pos.tables.view'],
        'orders:write' => ['sales.orders.edit', 'pos.tables.order'],
    ];

    // POST /api/v1/auth/exchange
    public function exchange(Request $request, AccountsSignIn $accounts): JsonResponse
    {
        if (! SignInMode::throughAccounts()) {
            return response()->json(['message' => 'Sign-in through Accounts is not set up on this server.'], 404);
        }

        $data = $request->validate([
            'access_token' => ['required', 'string', 'max:4096'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $user = $accounts->resolve($this->person($data['access_token']));
        } catch (SignInFailed $reason) {
            LoginHistory::record(LoginHistory::FAILED, null, null, $reason->getMessage(), $request);

            return response()->json(['message' => $reason->getMessage()], 403);
        }

        return $this->issue($user, $request, $data['device_name'] ?? null, 'Signed in to the app through Accounts');
    }

    // POST /api/v1/auth/login - only where no Accounts is set up.
    public function login(LoginRequest $request): JsonResponse
    {
        if (! SignInMode::localAllowed()) {
            return response()->json(['message' => 'Sign in with your Khana account.'], 403);
        }

        $request->validate(['device_name' => ['nullable', 'string', 'max:100']]);

        return $this->issue($request->verifiedUser(), $request, $request->input('device_name'), 'Signed in to the app');
    }

    // POST /api/v1/auth/logout - this phone only.
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->user()->currentAccessToken()->delete();

        ActivityLog::record('auth.logout', 'Signed out of the app', $user);

        return response()->json(['message' => 'Signed out.']);
    }

    // Asks Accounts who owns this token; never trusts the app.
    private function person(string $accessToken): SignedInPerson
    {
        $url = rtrim((string) config('khana.sso.accounts_url'), '/').'/api/v1/me';

        try {
            $profile = Http::withToken($accessToken)->acceptJson()->timeout(10)->get($url);
        } catch (Throwable $e) {
            throw new SignInFailed('Accounts could not be reached. Please try again in a moment.', previous: $e);
        }

        if (! $profile->successful() || ! is_array($profile->json())) {
            throw new SignInFailed('Accounts did not accept this sign-in. Please sign in again.');
        }

        return SignedInPerson::fromAccounts($profile->json(), ['access_token' => $accessToken]);
    }

    private function issue(User $user, Request $request, ?string $device, string $activity): JsonResponse
    {
        $abilities = $this->abilitiesFor($user);

        if ($abilities === []) {
            LoginHistory::record(LoginHistory::FAILED, $user->email, $user, 'no_app_abilities', $request);

            return response()->json(['message' => 'Your role has nothing it can do in the app. Ask the owner to check your role.'], 403);
        }

        $expiresAt = now()->addDays(self::TOKEN_DAYS);
        $token = $user->createToken('App: '.($device ?: 'phone'), $abilities, $expiresAt);

        LoginHistory::record(LoginHistory::SUCCESS, $user->email, $user, null, $request);
        $user->forceFill([
            'last_login_at' => now(),
            'last_seen_at' => now(),
            'login_count' => $user->login_count + 1,
        ])->saveQuietly();
        ActivityLog::record('auth.login', $activity, $user);

        $shop = $user->currentShop;

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toIso8601String(),
            'abilities' => $abilities,
            'user' => ['name' => $user->name, 'email' => $user->email],
            'shop' => $shop === null ? null : ['id' => $shop->id, 'name' => $shop->name],
        ]);
    }

    private function abilitiesFor(User $user): array
    {
        return array_keys(array_filter(
            self::ABILITY_PERMISSIONS,
            fn (array $permissions) => $user->canAny($permissions),
        ));
    }
}
