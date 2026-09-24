<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * Builds the single JSON envelope every AJAX endpoint answers with:
 *
 *   { success, message, data, redirect, errors }
 *
 * The json_success()/json_error() helpers are thin wrappers around this.
 * The class exists so the exception handlers in bootstrap/app.php can build
 * responses without depending on Composer's `files` autoload - a handler
 * that fatals while reporting another error hides the original problem.
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
