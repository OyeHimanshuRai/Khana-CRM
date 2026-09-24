<?php

use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/*
|--------------------------------------------------------------------------
| JSON response helpers
|--------------------------------------------------------------------------
|
| Every AJAX endpoint in the app answers with the same envelope so the
| front-end never has to guess at the shape of a response:
|
|   { success, message, data, redirect, errors }
|
| `public/assets/js/app.js` reads exactly these keys.
|
| These are conveniences for controllers and middleware. The real
| implementation lives in App\Support\ApiResponse, which framework-level
| code (bootstrap/app.php) uses directly.
|
*/

if (! function_exists('json_success')) {
    /**
     * Build a successful JSON response.
     *
     * @param  array<string, mixed>  $data
     */
    function json_success(
        string $message = 'Done.',
        array $data = [],
        ?string $redirect = null,
        int $status = 200
    ): JsonResponse {
        return ApiResponse::success($message, $data, $redirect, $status);
    }
}

if (! function_exists('json_error')) {
    /**
     * Build a failed JSON response.
     *
     * @param  array<string, array<int, string>>  $errors  Keyed by field name.
     */
    function json_error(
        string $message = 'Something went wrong.',
        array $errors = [],
        int $status = 422
    ): JsonResponse {
        return ApiResponse::error($message, $errors, $status);
    }
}
