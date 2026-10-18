<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Support\CurrentShop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// The Partner app's push address (an FCM token), kept beside the browsers' Web Push rows and told apart by kind.
class DeviceController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:500'],
            'label' => ['nullable', 'string', 'max:120'],
        ]);

        $shopId = CurrentShop::id();

        if ($shopId === null) {
            return response()->json(['message' => 'Choose an outlet first.'], 422);
        }

        // One row per phone across every outlet: a phone that moved outlet is the same phone.
        DeviceToken::query()->withoutGlobalScopes()->updateOrCreate(
            ['endpoint_hash' => DeviceToken::hashFor($data['token'])],
            [
                'shop_id' => $shopId,
                'user_id' => $request->user()->id,
                'kind' => DeviceToken::FCM,
                'endpoint' => $data['token'],
                'label' => $data['label'] ?? null,
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
                'last_used_at' => now(),
            ],
        );

        return response()->json(['message' => 'Notifications will reach this phone.'], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:500']]);

        DeviceToken::query()->withoutGlobalScopes()
            ->where('endpoint_hash', DeviceToken::hashFor($data['token']))
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json(['message' => 'This phone will get no more notifications.']);
    }
}
