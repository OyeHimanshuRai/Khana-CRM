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
        | What CRM, Accounts and other billing software may ask of ERP
        | (contracts/api/erp.yaml), on machine tokens issued by Accounts.
        */
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : route('login'));
        $middleware->redirectUsersTo(fn () => route('home'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The one envelope every JSON caller sees, the kit's ApiResponse,
        // so a screen parses one shape whichever product answered.
        $exceptions->render(function (ValidationException $e, Request $request) {
            return $request->expectsJson() && ! $request->is('api/*')
                ? ApiResponse::error($e->getMessage(), $e->errors(), $e->status)
                : null;
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return $request->expectsJson() && ! $request->is('api/*')
                ? ApiResponse::error('Your session has expired. Please sign in again.', [], 401)
                : null;
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! $request->expectsJson() || $request->is('api/*')) {
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
