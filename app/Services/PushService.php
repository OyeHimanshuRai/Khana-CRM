<?php

namespace App\Services;

use App\Models\DeviceToken;
use Illuminate\Support\Collection;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Pushing to a screen that is asleep (SRS 16).
 *
 * ---------------------------------------------------------------------------
 * Never load-bearing
 * ---------------------------------------------------------------------------
 *
 * The same rule the broadcast event follows. A kitchen ticket reaches the
 * board through the poll, always; push only wakes a tablet whose screen has
 * gone off. So every failure here is swallowed and reported, and nothing
 * upstream may depend on a message having arrived.
 *
 * ---------------------------------------------------------------------------
 * A dead subscription is deleted, not retried
 * ---------------------------------------------------------------------------
 *
 * Subscriptions expire constantly - a browser update, a cleared site setting,
 * a reinstalled tablet. The push service answers 404 or 410 for those, and
 * that means "this will never work again" rather than "try later". Keeping
 * the row would mean a kitchen with six tablets listed and two that exist.
 */
class PushService
{
    public function isEnabled(): bool
    {
        return (bool) config('push.enabled')
            && filled(config('push.vapid.public_key'))
            && filled(config('push.vapid.private_key'));
    }

    /**
     * Send one notification to every device registered for a branch.
     *
     * Returns how many were accepted by the push service, which is not the
     * same as how many buzzed - the device may be off, and the service will
     * hold it for the TTL. There is no way to know from here, and a method
     * that pretended otherwise would be lying.
     */
    public function toShop(int $shopId, string $title, string $body, ?string $url = null): int
    {
        if (! $this->isEnabled()) {
            return 0;
        }

        $devices = DeviceToken::query()
            ->withoutGlobalScopes()
            ->where('shop_id', $shopId)
            ->webPush()
            ->get();

        return $this->send($devices, $title, $body, $url);
    }

    /**
     * @param  Collection<int, DeviceToken>  $devices
     */
    public function send(Collection $devices, string $title, string $body, ?string $url = null): int
    {
        if ($devices->isEmpty() || ! $this->isEnabled()) {
            return 0;
        }

        try {
            $push = new WebPush([
                'VAPID' => [
                    'subject' => (string) config('push.vapid.subject'),
                    'publicKey' => (string) config('push.vapid.public_key'),
                    'privateKey' => (string) config('push.vapid.private_key'),
                ],
            ]);

            $push->setDefaultOptions(['TTL' => (int) config('push.ttl', 900)]);
        } catch (Throwable $e) {
            report($e);

            return 0;
        }

        $payload = json_encode([
            'title' => $title,
            'body' => $body,
            'url' => $url,
        ], JSON_UNESCAPED_SLASHES);

        $byEndpoint = [];

        foreach ($devices as $device) {
            try {
                $push->queueNotification(
                    Subscription::create([
                        'endpoint' => $device->endpoint,
                        'publicKey' => $device->p256dh,
                        'authToken' => $device->auth,
                    ]),
                    $payload,
                );

                $byEndpoint[$device->endpoint] = $device;
            } catch (Throwable $e) {
                report($e);
            }
        }

        $accepted = 0;

        try {
            foreach ($push->flush() as $report) {
                $endpoint = $report->getRequest()->getUri()->__toString();
                $device = $byEndpoint[$endpoint] ?? null;

                if ($report->isSuccess()) {
                    $accepted++;
                    $device?->forceFill(['last_used_at' => now()])->save();

                    continue;
                }

                /*
                 | Gone for good. The library reports this as "subscription
                 | expired", which is the push service's 404 or 410 - and a
                 | row kept after that is a tablet on a list that does not
                 | exist.
                 */
                if ($report->isSubscriptionExpired()) {
                    $device?->delete();
                }
            }
        } catch (Throwable $e) {
            report($e);
        }

        return $accepted;
    }

    /**
     * Record a browser's subscription, or refresh one already known.
     *
     * @param  array{endpoint: string, keys: array{p256dh: string, auth: string}}  $subscription
     */
    public function subscribe(array $subscription, int $shopId, ?int $userId, ?string $label, ?string $agent): DeviceToken
    {
        return DeviceToken::query()->updateOrCreate(
            ['endpoint_hash' => DeviceToken::hashFor($subscription['endpoint'])],
            [
                'shop_id' => $shopId,
                'user_id' => $userId,
                'kind' => DeviceToken::WEB_PUSH,
                'endpoint' => $subscription['endpoint'],
                'p256dh' => $subscription['keys']['p256dh'] ?? null,
                'auth' => $subscription['keys']['auth'] ?? null,
                'label' => $label,
                'user_agent' => $agent === null ? null : mb_substr($agent, 0, 255),
                'last_used_at' => null,
            ],
        );
    }

    /** Forget a device. */
    public function unsubscribe(string $endpoint): void
    {
        DeviceToken::query()
            ->withoutGlobalScopes()
            ->where('endpoint_hash', DeviceToken::hashFor($endpoint))
            ->delete();
    }
}
