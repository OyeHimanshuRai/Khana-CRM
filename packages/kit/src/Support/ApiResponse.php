<?php

namespace Khana\Kit\Support;

use Illuminate\Http\JsonResponse;

/**
 * The single JSON envelope every screen's AJAX endpoint answers with:
 *
 *   { success, message, data, redirect, errors }
 *
 * Shared so that the plain-JavaScript components in every service can rely
 * on one response shape.
 */
final class ApiResponse
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function success(
        string $message = 'Done.',
        array $data = [],
        ?string $redirect = null,
        int $status = 200
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => (object) $data,
            'redirect' => $redirect,
        ], $status);
    }

    /**
     * @param  array<string, array<int, string>>  $errors  Keyed by field name.
     */
    public static function error(
        string $message = 'Something went wrong.',
        array $errors = [],
        int $status = 422
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => (object) $errors,
        ], $status);
    }
}
