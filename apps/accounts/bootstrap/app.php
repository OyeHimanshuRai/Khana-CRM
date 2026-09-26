<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Khana\Kit\Support\ApiResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        /*
        | The reference API CRM and ERP read (contracts/api/accounts.yaml):
        | /me for the person who just signed in, and the tenant snapshot for
        | a first sync. Passport registers /oauth/* itself.
        */
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            // Runs the business here: outlets, team, plan. See EnsureOwner.
            'owner' => \App\Http\Middleware\EnsureOwner::class,
            // The back office: platform staff only.
            'platform' => \App\Http\Middleware\EnsurePlatformAdmin::class,
        ]);

        // Where a signed-out person and a signed-in one each land. The API
        // has nowhere to send a browser, so a missing token throws (below).
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : route('login'));
        $middleware->redirectUsersTo(fn () => route('home'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The one envelope every JSON caller sees, matching the kit's
        // ApiResponse so CRM and ERP parse one shape.
        $exceptions->render(function (ValidationException $e, Request $request) {
            return $request->expectsJson()
                ? ApiResponse::error($e->getMessage(), $e->errors(), $e->status)
                : null;
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'This request needs a valid token from Khana Accounts. Send it as: Authorization: Bearer <token>.',
                ], 401);
            }

            return $request->expectsJson()
                ? ApiResponse::error('Your session has expired. Please sign in again.', [], 401)
                : null;
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            $message = match ($e->getStatusCode()) {
                419 => 'Your session has expired. Please refresh the page and try again.',
                429 => 'Too many requests. Please slow down and try again shortly.',
                default => $e->getMessage() ?: 'Something went wrong.',
            };

            return ApiResponse::error($message, [], $e->getStatusCode());
        });
    })->create();
