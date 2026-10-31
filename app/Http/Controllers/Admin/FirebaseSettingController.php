<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\DeviceToken;
use App\Models\FirebaseSetting;
use App\Services\FcmService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * Settings > Firebase: the platform's one Firebase project.
 *
 * Platform-only (PlatformAbilities lists settings.firebase.): a restaurant's
 * staff never see it, because there is one project for every restaurant.
 *
 * The service account is write-only from here. It is never put back into
 * the form - the screen shows which account is set and lets it be replaced,
 * which is all anybody needs, and a private key rendered into a page is a
 * private key in the browser's history.
 */
class FirebaseSettingController extends Controller
{
    public function edit(): View
    {
        $settings = FirebaseSetting::current();

        return view('admin.settings.firebase', [
            'settings' => $settings,
            'devices' => DeviceToken::query()->withoutGlobalScopes()->where('kind', DeviceToken::FCM)->count(),
            'myDevices' => DeviceToken::query()->withoutGlobalScopes()
                ->where('kind', DeviceToken::FCM)->where('user_id', auth()->id())->count(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'is_enabled' => ['nullable', 'boolean'],
            'project_id' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9-]+$/'],
            'api_key' => ['nullable', 'string', 'max:120'],
            'auth_domain' => ['nullable', 'string', 'max:190'],
            'storage_bucket' => ['nullable', 'string', 'max:190'],
            'messaging_sender_id' => ['nullable', 'string', 'max:40', 'regex:/^[0-9]+$/'],
            'app_id' => ['nullable', 'string', 'max:120'],
            'measurement_id' => ['nullable', 'string', 'max:40'],
            'vapid_key' => ['nullable', 'string', 'max:200'],
            'service_account' => ['nullable', 'string', 'max:20000'],
        ], [
            'project_id.regex' => 'The project ID is lower-case letters, numbers and dashes, like my-restaurant-app.',
            'messaging_sender_id.regex' => 'The sender ID is a number.',
        ]);

        $settings = FirebaseSetting::current();

        $fill = collect($data)->except(['service_account', 'is_enabled'])->all();
        $fill['is_enabled'] = $request->boolean('is_enabled');

        /*
         | The JSON file Firebase downloads (Project settings > Service
         | accounts > Generate new private key), pasted whole. Blank keeps the
         | one already stored, so saving the rest of the form never wipes it.
         */
        if (filled($data['service_account'] ?? null)) {
            $account = json_decode((string) $data['service_account'], true);

            if (! is_array($account) || blank($account['client_email'] ?? null) || blank($account['private_key'] ?? null)) {
                return json_error('That is not a service account file.', [
                    'service_account' => ['Paste the whole JSON file Firebase gives you - it contains "client_email" and "private_key".'],
                ]);
            }

            if (openssl_pkey_get_private((string) $account['private_key']) === false) {
                return json_error('The private key in that file cannot be read.', [
                    'service_account' => ['The private key in that file cannot be read. Download a fresh one from Firebase.'],
                ]);
            }

            $fill['service_account'] = $account;
            $fill['project_id'] = $fill['project_id'] ?: ($account['project_id'] ?? null);
        }

        $settings->forceFill($fill)->save();

        ActivityLog::record('settings.firebase', 'Updated the Firebase settings', $settings);

        if ($settings->is_enabled && ! $settings->isReady()) {
            $missing = $settings->hasWebConfig() ? 'the service account' : 'the web app config and the VAPID key';

            return json_success('Saved. Notifications stay off until '.$missing.' '.($missing === 'the service account' ? 'is' : 'are').' filled in.');
        }

        return json_success($settings->isReady() ? 'Saved. Firebase notifications are on.' : 'Saved.');
    }

    /**
     * Prove it works: get a token from Google, then buzz the devices the
     * person pressing the button has registered.
     */
    public function test(FcmService $fcm): JsonResponse
    {
        $settings = FirebaseSetting::current();

        if (! $settings->isReady()) {
            return json_error('Fill in and switch on Firebase first.');
        }

        try {
            $fcm->accessToken();
        } catch (Throwable $e) {
            $this->record($settings, 'Google refused the service account');

            return json_error('Google refused the service account: '.$e->getMessage());
        }

        $devices = DeviceToken::query()->withoutGlobalScopes()
            ->where('kind', DeviceToken::FCM)
            ->where('user_id', auth()->id())
            ->get();

        if ($devices->isEmpty()) {
            $this->record($settings, 'Credentials work; no device registered');

            return json_success('The credentials work. Now press "Turn on notifications on this device" and test again.');
        }

        $sent = $fcm->send($devices, 'Test notification', 'Firebase is working. This is what new orders will look like.', route('admin.alerts.index'), ['type' => 'test']);

        $this->record($settings, "Sent to {$sent} of {$devices->count()} device(s)");

        return $sent > 0
            ? json_success("Sent to {$sent} device(s). It should appear in a few seconds.")
            : json_error('Google accepted the credentials but no device took the message. Turn notifications on again on this device.');
    }

    private function record(FirebaseSetting $settings, string $result): void
    {
        $settings->forceFill(['last_tested_at' => now(), 'last_test_result' => $result])->save();
    }
}
