<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\DeviceToken;
use App\Models\User;
use App\Support\NotificationPreferences;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A new alert, pushed to the devices of the people who may see it.
 *
 * The bell already polls; this is for the screen that is closed - the owner
 * whose phone is in their pocket when a table orders. Both channels the app
 * knows are tried: Web Push (VAPID, PushService) and Firebase (FcmService).
 * Whichever the device registered with is the one it gets.
 *
 * Who receives it is decided by Alert::isVisibleTo(), the same rule the bell
 * uses, so a push can never tell somebody about an alert they could not then
 * open. A cashier's tablet does not buzz for a subscription payment.
 *
 * Runs after the response (see AlertService::raise), so a guest pressing
 * Order never waits on Google.
 */
class AlertPushService
{
    public function __construct(
        private readonly PushService $webPush,
        private readonly FcmService $fcm,
    ) {}

    /** @return int how many devices were accepted, across both channels */
    public function push(Alert $alert): int
    {
        // Neither channel configured is the default, and then there is
        // nobody to look up.
        if (! $this->webPush->isEnabled() && ! $this->fcm->isEnabled()) {
            return 0;
        }

        try {
            $devices = $this->devicesFor($alert);

            if ($devices->isEmpty()) {
                return 0;
            }

            $title = $alert->title;
            $body = (string) ($alert->body ?: ($alert->shop?->name ?? ''));
            $url = $alert->link ? url($alert->link) : route('admin.alerts.index');
            $data = ['type' => $alert->type, 'alert_id' => (string) $alert->id];

            return $this->webPush->send($devices->where('kind', DeviceToken::WEB_PUSH)->values(), $title, $body, $url)
                + $this->fcm->send($devices->where('kind', DeviceToken::FCM)->values(), $title, $body, $url, $data);
        } catch (Throwable $e) {
            Log::warning('Alert push failed', ['alert' => $alert->id, 'error' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * Registered devices whose owner may see this alert.
     *
     * Chosen by person, not by where the device was registered: a manager
     * covering two branches registers their phone once, in whichever branch
     * they were standing in, and the bell reaches both (Alert::acrossShopsFor)
     * - so the push has to as well. That is everybody on the branch's pivot,
     * plus the platform's own accounts, who reach every branch.
     *
     * @return Collection<int, DeviceToken>
     */
    public function devicesFor(Alert $alert): Collection
    {
        $people = User::query()
            ->withoutGlobalScopes()
            ->where(fn ($q) => $q
                ->whereNull('tenant_id')
                ->orWhereHas('shops', fn ($shops) => $shops->where('shops.id', $alert->shop_id)))
            ->pluck('id');

        return DeviceToken::query()
            ->withoutGlobalScopes()
            ->whereIn('user_id', $people)
            ->with(['user' => fn ($q) => $q->withoutGlobalScopes()])
            ->get()
            ->filter(fn (DeviceToken $device) => $device->user !== null
                && $device->user->is_active
                && $alert->isVisibleTo($device->user)
                // Their own switch for this kind (Profile > Notifications).
                && NotificationPreferences::wants($device->user, $alert->type, 'push'))
            ->values();
    }
}
