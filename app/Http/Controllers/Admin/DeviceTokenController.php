<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Models\User;
use App\Support\CurrentShop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * "Notify me on this device": the browser hands over its Firebase token.
 *
 * Anybody signed in may register their own device - like the bell, what
 * they are then sent is gated per alert (Alert::isVisibleTo), not here.
 *
 * The token is the device's identity, hashed into the same unique index the
 * Web Push endpoints use, so re-registering on every page load (which
 * firebase-push.js does, to pick up a token Google rotated) updates one row
 * rather than piling up copies.
 */
class DeviceTokenController extends Controller
{
    public function storeFcm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'min:20', 'max:500'],
            'label' => ['nullable', 'string', 'max:120'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $shopId = CurrentShop::id() ?? $user->current_shop_id ?? CurrentShop::accessibleIds()[0] ?? null;

        if ($shopId === null) {
            return json_error('Pick a branch first, then turn notifications on.');
        }

        DeviceToken::query()->withoutGlobalScopes()->updateOrCreate(
            ['endpoint_hash' => DeviceToken::hashFor($data['token'])],
            [
                'shop_id' => $shopId,
                'user_id' => $user->id,
                'kind' => DeviceToken::FCM,
                'endpoint' => $data['token'],
                'label' => $data['label'] ?? null,
                'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
                'last_used_at' => now(),
            ],
        );

        return json_success('This device will now get notifications.');
    }
}
