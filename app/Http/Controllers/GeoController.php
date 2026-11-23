<?php

namespace App\Http\Controllers;

use App\Models\City;
use App\Models\Country;
use App\Models\State;
use Illuminate\Http\JsonResponse;

/**
 * The lists behind the dependent Country > State > City dropdowns.
 *
 * Public and read-only (the sign-up form is public). Every query goes through
 * Eloquent's query builder, so values are bound as prepared-statement
 * parameters; the route ids are also constrained to digits.
 */
class GeoController extends Controller
{
    public function countries(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => Country::query()->orderBy('country_name')
                ->get(['id', 'country_name', 'iso_code', 'phone_code', 'min_length', 'max_length']),
        ]);
    }

    public function states(int $country): JsonResponse
    {
        if (! Country::query()->whereKey($country)->exists()) {
            return $this->notFound('That country was not found.');
        }

        return response()->json([
            'success' => true,
            'data' => State::query()->where('country_id', $country)->orderBy('state_name')->get(['id', 'state_name']),
        ]);
    }

    public function cities(int $state): JsonResponse
    {
        if (! State::query()->whereKey($state)->exists()) {
            return $this->notFound('That state was not found.');
        }

        return response()->json([
            'success' => true,
            'data' => City::query()->where('state_id', $state)->orderBy('city_name')->get(['id', 'city_name']),
        ]);
    }

    private function notFound(string $message): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'errors' => (object) []], 404);
    }
}
