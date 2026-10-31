<?php

namespace App\Services;

use App\Models\DeviceToken;
use App\Models\FirebaseSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Firebase Cloud Messaging, HTTP v1 (SRS 16).
 *
 * ---------------------------------------------------------------------------
 * No SDK, on purpose
 * ---------------------------------------------------------------------------
 *
 * Google's PHP client is a large dependency for two HTTP calls: trade the
 * service account for an access token (a JWT signed with its private key),
 * then POST one message per device. Both are below, with openssl and the
 * HTTP client Laravel already ships.
 *
 * ---------------------------------------------------------------------------
 * Never load-bearing, like PushService
 * ---------------------------------------------------------------------------
 *
 * The bell polls and the kitchen board polls. A push only reaches a screen
 * that is asleep or closed. So nothing here throws to its caller: a failure
 * is logged and the send reports 0, and an order is never refused because
 * Google was slow.
 *
 * A token Google says is gone (UNREGISTERED, or the token is malformed) is
 * deleted, not retried - the same rule PushService applies to 404/410.
 */
class FcmService
{
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /*
     | No constructor injection of the settings: the container would hand over
     | an empty, unsaved model rather than null, and Firebase would read as
     | permanently off. Asked fresh each time instead (it is cached).
     */
    public function settings(): FirebaseSetting
    {
        return FirebaseSetting::current();
    }

    public function isEnabled(): bool
    {
        return $this->settings()->isReady();
    }

    /**
     * Send one notification to these devices. Returns how many Google accepted.
     *
     * @param  Collection<int, DeviceToken>  $devices
     * @param  array<string, string>  $data  extra keys the service worker can read
     */
    public function send(Collection $devices, string $title, string $body, ?string $url = null, array $data = []): int
    {
        $devices = $devices->where('kind', DeviceToken::FCM);

        if ($devices->isEmpty() || ! $this->isEnabled()) {
            return 0;
        }

        try {
            $token = $this->accessToken();
        } catch (Throwable $e) {
            Log::warning('FCM: could not get an access token', ['error' => $e->getMessage()]);

            return 0;
        }

        $accepted = 0;
        $endpoint = sprintf('https://fcm.googleapis.com/v1/projects/%s/messages:send', $this->settings()->project_id);

        foreach ($devices as $device) {
            try {
                $response = Http::withToken($token)
                    ->timeout(8)
                    ->post($endpoint, ['message' => $this->message($device->endpoint, $title, $body, $url, $data)]);
            } catch (Throwable $e) {
                Log::warning('FCM: send failed', ['device' => $device->id, 'error' => $e->getMessage()]);

                continue;
            }

            if ($response->successful()) {
                $accepted++;
                $device->forceFill(['last_used_at' => now()])->saveQuietly();

                continue;
            }

            if ($this->isDead($response->json())) {
                $device->delete();

                continue;
            }

            Log::warning('FCM: send refused', ['device' => $device->id, 'status' => $response->status(), 'body' => $response->body()]);
        }

        return $accepted;
    }

    /**
     * A short-lived OAuth token for the messaging scope.
     *
     * Cached for a little under its hour, per service account, so a busy
     * evening does not sign a JWT for every ticket.
     *
     * @throws RuntimeException
     */
    public function accessToken(): string
    {
        $account = $this->settings()->service_account;

        if (! is_array($account) || blank($account['client_email'] ?? null) || blank($account['private_key'] ?? null)) {
            throw new RuntimeException('The Firebase service account is not set.');
        }

        $key = 'fcm.token.'.sha1($account['client_email']);

        return Cache::remember($key, 3300, function () use ($account) {
            $response = Http::asForm()->timeout(10)->post($account['token_uri'] ?? self::TOKEN_URL, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $this->assertion($account),
            ]);

            if (! $response->successful() || blank($response->json('access_token'))) {
                throw new RuntimeException('Google refused the service account: '.$response->body());
            }

            return (string) $response->json('access_token');
        });
    }

    /**
     * The signed JWT Google trades for an access token (RFC 7523).
     *
     * @param  array<string, mixed>  $account
     */
    public function assertion(array $account): string
    {
        $now = time();

        $segments = [
            $this->base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            $this->base64url(json_encode([
                'iss' => $account['client_email'],
                'scope' => self::SCOPE,
                'aud' => $account['token_uri'] ?? self::TOKEN_URL,
                'iat' => $now,
                'exp' => $now + 3600,
            ])),
        ];

        $key = openssl_pkey_get_private((string) $account['private_key']);

        if ($key === false || ! openssl_sign(implode('.', $segments), $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('The service account private key could not be read.');
        }

        $segments[] = $this->base64url($signature);

        return implode('.', $segments);
    }

    /**
     * One FCM message.
     *
     * Notification and data both: the notification is what the browser shows
     * when the tab is closed, and the data is what an open tab - or the
     * service worker's click handler - reads to know where to go.
     *
     * @param  array<string, string>  $data
     * @return array<string, mixed>
     */
    public function message(string $token, string $title, string $body, ?string $url, array $data): array
    {
        $message = [
            'token' => $token,
            'notification' => ['title' => $title, 'body' => $body],
            'data' => array_map('strval', $data + array_filter(['url' => $url])),
            'webpush' => [
                'notification' => [
                    'icon' => asset('favicon.ico'),
                    // One per kind of event, so ten new orders do not stack
                    // ten banners on a tablet that was face down.
                    'tag' => (string) ($data['type'] ?? 'alert'),
                    'renotify' => true,
                ],
            ],
        ];

        if ($url !== null) {
            $message['webpush']['fcm_options'] = ['link' => $url];
        }

        return $message;
    }

    /** Is this the error FCM gives for a token that will never work again? */
    private function isDead(mixed $error): bool
    {
        $error = is_array($error) ? ($error['error'] ?? []) : [];
        $status = $error['status'] ?? null;

        if ($status === 'NOT_FOUND' || $status === 'UNREGISTERED') {
            return true;
        }

        foreach ((array) ($error['details'] ?? []) as $detail) {
            if (in_array($detail['errorCode'] ?? null, ['UNREGISTERED', 'INVALID_ARGUMENT'], true)) {
                return true;
            }
        }

        return false;
    }

    private function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
