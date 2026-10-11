<?php

namespace Khana\Kit\Sso;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Tokens this service presents when it reads another's API (OAuth 2 client
 * credentials, issued by Accounts). Cached until shortly before they expire,
 * so a screen that shows ERP's stock panel does not cost a trip to Accounts
 * every time.
 *
 *   $this->tokens->http(['stock:read'])->get($erpUrl.'/api/v1/outlets/'.$id.'/items/'.$item.'/stock');
 */
final class MachineTokens
{
    public function token(array $scopes): string
    {
        sort($scopes);
        $key = 'khana:machine-token:'.sha1(implode(' ', $scopes));

        $cached = Cache::get($key);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $url = rtrim((string) config('khana.sso.accounts_url'), '/');
        $clientId = (string) config('khana.sso.machine_client_id');
        $secret = (string) config('khana.sso.machine_client_secret');

        if ($url === '' || $clientId === '' || $secret === '') {
            throw new RuntimeException('Machine tokens are not set up: KHANA_ACCOUNTS_URL, KHANA_MACHINE_CLIENT_ID and KHANA_MACHINE_CLIENT_SECRET are needed.');
        }

        try {
            $response = Http::asForm()->acceptJson()->timeout(10)->post($url.'/oauth/token', [
                'grant_type' => 'client_credentials',
                'client_id' => $clientId,
                'client_secret' => $secret,
                'scope' => implode(' ', $scopes),
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException('Khana Accounts could not be reached for a token.', previous: $e);
        }

        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new RuntimeException('Khana Accounts refused a token for: '.implode(' ', $scopes).'.');
        }

        Cache::put($key, $token, max(1, (int) $response->json('expires_in', 3600) - 60));

        return $token;
    }

    public function http(array $scopes): PendingRequest
    {
        return Http::withToken($this->token($scopes))->acceptJson()->timeout(10);
    }
}
