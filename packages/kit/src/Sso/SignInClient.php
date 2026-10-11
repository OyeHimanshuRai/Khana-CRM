<?php

namespace Khana\Kit\Sso;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sign-in through Accounts: OAuth 2 authorization code with PKCE.
 *
 *   1. redirect() sends the person to Accounts with a one-time state and a
 *      code challenge, both remembered in this product's session.
 *   2. Accounts sends them back with a code. callback() checks the state,
 *      swaps the code (and the verifier only this session knows) for a
 *      token, and asks Accounts who the person is.
 *
 * Someone already signed in to Accounts comes straight back, which is what
 * makes switching between CRM and ERP silent.
 *
 * No client secret: a browser flow cannot keep one, and PKCE is what stops a
 * stolen code being redeemed by anybody else.
 */
final class SignInClient
{
    public const SESSION_KEY = 'khana.sso';

    public function redirect(Request $request): RedirectResponse
    {
        $state = Str::random(40);
        $verifier = Str::random(96);

        $request->session()->put(self::SESSION_KEY, ['state' => $state, 'verifier' => $verifier]);

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->config('client_id'),
            'redirect_uri' => $this->redirectUri(),
            'scope' => implode(' ', (array) config('khana.sso.scopes', [])),
            'state' => $state,
            'code_challenge' => self::challenge($verifier),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);

        return redirect()->away($this->accountsUrl().'/oauth/authorize?'.$query);
    }

    /**
     * @throws SignInFailed
     */
    public function callback(Request $request): SignedInPerson
    {
        // Pulled, not read: a state is good for one attempt.
        $pending = $request->session()->pull(self::SESSION_KEY);

        if ($request->filled('error')) {
            throw new SignInFailed('Accounts did not sign you in: '.Str::limit((string) ($request->query('error_description') ?? $request->query('error')), 200));
        }

        $state = $request->query('state');

        if (! is_array($pending) || ! is_string($state) || ! hash_equals((string) $pending['state'], $state)) {
            throw new SignInFailed('This sign-in link has expired or was not started here. Please sign in again.');
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            throw new SignInFailed('Accounts sent no sign-in code.');
        }

        try {
            $token = Http::asForm()->acceptJson()->timeout(10)->post($this->accountsUrl().'/oauth/token', [
                'grant_type' => 'authorization_code',
                'client_id' => $this->config('client_id'),
                'redirect_uri' => $this->redirectUri(),
                'code_verifier' => $pending['verifier'],
                'code' => $code,
            ]);

            if (! $token->successful() || ! is_string($token->json('access_token'))) {
                throw new SignInFailed('Accounts refused the sign-in code.');
            }

            $profile = Http::withToken($token->json('access_token'))->acceptJson()->timeout(10)
                ->get($this->accountsUrl().'/api/v1/me');

            if (! $profile->successful() || ! is_array($profile->json())) {
                throw new SignInFailed('Accounts did not say who signed in.');
            }
        } catch (SignInFailed $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new SignInFailed('Accounts could not be reached. Please try again in a moment.', previous: $e);
        }

        return SignedInPerson::fromAccounts($profile->json(), $token->json());
    }

    /** base64url(sha256(verifier)), the S256 method. */
    public static function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    public function redirectUri(): string
    {
        return (string) (config('khana.sso.redirect') ?: route('khana.sso.callback'));
    }

    private function accountsUrl(): string
    {
        return rtrim($this->config('accounts_url'), '/');
    }

    private function config(string $key): string
    {
        $value = config('khana.sso.'.$key);

        if (! is_string($value) || $value === '') {
            throw new SignInFailed("Sign-in is not set up: khana.sso.{$key} is empty.");
        }

        return $value;
    }
}
